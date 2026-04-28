package install

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/evolution-cms/installer/internal/domain"
)

const legacyStoreCatalogURL = "https://extras.evo.im/get.php"
const extrasRuntimeCacheDir = "core/.evo-installer-runtime"

type extrasDocblock struct {
	Name        string
	Description string
	Tags        map[string]string
}

type legacyStoreStartResponse struct {
	Version     string                             `json:"version"`
	Category    []legacyStoreCategory              `json:"category"`
	AllCategory map[string][]legacyStoreCatalogRaw `json:"allcategory"`
}

type legacyStoreScalar string

type legacyStoreCategory struct {
	ID    legacyStoreScalar `json:"id"`
	Title string            `json:"title"`
}

type legacyStoreCatalogRaw struct {
	ID           legacyStoreScalar   `json:"id"`
	URL          legacyStoreURLField `json:"url"`
	Method       string              `json:"method"`
	Type         string              `json:"type"`
	Downloads    string              `json:"downloads"`
	Dependencies string              `json:"dependencies"`
	Description  string              `json:"description"`
	Title        string              `json:"title"`
	Author       string              `json:"author"`
	NameInMODX   string              `json:"name_in_modx"`
	Deprecated   string              `json:"deprecated"`
	Image        string              `json:"image"`
	CartImage    string              `json:"cartimage"`
}

type legacyStoreURLField struct {
	FieldValue []legacyStoreVersion `json:"fieldValue"`
}

type legacyStoreVersion struct {
	File    string `json:"file"`
	Version string `json:"version"`
	Date    string `json:"date"`
}

func (s *legacyStoreScalar) UnmarshalJSON(raw []byte) error {
	var value any
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(&value); err != nil {
		return err
	}

	switch v := value.(type) {
	case string:
		*s = legacyStoreScalar(v)
	case json.Number:
		*s = legacyStoreScalar(v.String())
	case nil:
		*s = ""
	default:
		*s = legacyStoreScalar(fmt.Sprint(v))
	}
	return nil
}

func loadAllExtrasCatalogs(ctx context.Context, workDir, token string) ([]domain.ExtrasPackage, []domain.ExtrasSelection, []string, error) {
	coreDir, warn, err := checkExtrasPrereqs(ctx, workDir)
	if err != nil {
		return nil, nil, nil, err
	}

	pkgs := make([]domain.ExtrasPackage, 0, 64)
	warnings := []string{}
	if warn != "" {
		warnings = append(warnings, warn)
	}

	managed, err := fetchExtrasList(ctx, coreDir, token)
	if err != nil {
		warnings = append(warnings, "Managed extras unavailable: "+err.Error())
	} else {
		pkgs = append(pkgs, managed...)
	}

	bundled, err := loadBundledInlineExtras(workDir)
	if err != nil {
		warnings = append(warnings, "Bundled defaults unavailable: "+err.Error())
	} else {
		pkgs = append(pkgs, bundled...)
	}

	legacy, err := loadLegacyStoreCatalog(ctx)
	if err != nil {
		warnings = append(warnings, "Legacy Store catalog unavailable: "+err.Error())
	} else {
		pkgs = append(pkgs, legacy...)
	}

	pkgs = dedupeExtrasPackages(pkgs)
	sortExtrasPackages(pkgs)
	defaults := defaultExtrasSelections(pkgs)
	return pkgs, defaults, warnings, nil
}

func dedupeExtrasPackages(pkgs []domain.ExtrasPackage) []domain.ExtrasPackage {
	if len(pkgs) == 0 {
		return nil
	}
	out := make([]domain.ExtrasPackage, 0, len(pkgs))
	seen := map[string]int{}
	for _, pkg := range pkgs {
		key := strings.ToLower(strings.TrimSpace(pkg.Name))
		if key == "" {
			continue
		}
		if idx, ok := seen[key]; ok {
			if extrasSourcePriority(pkg.Source) < extrasSourcePriority(out[idx].Source) {
				out[idx] = pkg
			}
			continue
		}
		seen[key] = len(out)
		out = append(out, pkg)
	}
	return out
}

func extrasSourcePriority(source string) int {
	switch strings.TrimSpace(source) {
	case "bundled-inline":
		return 0
	case "managed":
		return 1
	case "legacy-store":
		return 2
	default:
		return 10
	}
}

func defaultExtrasSelections(pkgs []domain.ExtrasPackage) []domain.ExtrasSelection {
	out := make([]domain.ExtrasSelection, 0, len(pkgs))
	for _, pkg := range pkgs {
		if !pkg.Preselected {
			continue
		}
		out = append(out, domain.ExtrasSelection{
			ID:      pkg.ID,
			Name:    pkg.Name,
			Source:  pkg.Source,
			Version: strings.TrimSpace(defaultExtrasInstallVersion(pkg)),
		})
	}
	return out
}

func sortExtrasPackages(pkgs []domain.ExtrasPackage) {
	order := map[string]int{
		"bundled-inline": 0,
		"managed":        1,
		"legacy-store":   2,
	}
	sort.SliceStable(pkgs, func(i, j int) bool {
		oi := order[pkgs[i].Source]
		oj := order[pkgs[j].Source]
		if oi != oj {
			return oi < oj
		}
		if pkgs[i].Section != pkgs[j].Section {
			return pkgs[i].Section < pkgs[j].Section
		}
		return strings.ToLower(pkgs[i].Name) < strings.ToLower(pkgs[j].Name)
	})
}

func loadBundledInlineExtras(workDir string) ([]domain.ExtrasPackage, error) {
	projectDir := absDir(workDir)
	baseDir := filepath.Join(projectDir, "install", "assets")
	pathPrefix := filepath.Join("install", "assets")
	if _, err := os.Stat(baseDir); err != nil {
		if !os.IsNotExist(err) {
			return nil, err
		}
		baseDir = filepath.Join(projectDir, extrasRuntimeCacheDir, "install", "assets")
		pathPrefix = filepath.Join(extrasRuntimeCacheDir, "install", "assets")
	}
	specs := []struct {
		subdir  string
		kind    string
		section string
	}{
		{subdir: "plugins", kind: "plugin", section: "Bundled defaults"},
		{subdir: "modules", kind: "module", section: "Bundled defaults"},
	}

	pkgs := make([]domain.ExtrasPackage, 0, 12)
	for _, spec := range specs {
		dir := filepath.Join(baseDir, spec.subdir)
		entries, err := os.ReadDir(dir)
		if err != nil {
			if os.IsNotExist(err) {
				continue
			}
			return nil, err
		}
		for _, entry := range entries {
			if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".tpl") {
				continue
			}
			doc, err := parseExtrasDocblockFile(filepath.Join(dir, entry.Name()))
			if err != nil {
				continue
			}
			name := strings.TrimSpace(doc.Name)
			if name == "" {
				continue
			}
			installset := strings.ToLower(strings.TrimSpace(doc.Tags["installset"]))
			pkg := domain.ExtrasPackage{
				ID:                 "bundled-inline:" + strings.ToLower(name),
				Name:               name,
				Version:            strings.TrimSpace(doc.Tags["version"]),
				Description:        strings.TrimSpace(doc.Description),
				DefaultInstallMode: "bundled-inline",
				Source:             "bundled-inline",
				Section:            spec.section,
				Kind:               spec.kind,
				InstallMode:        "bundled-inline",
				Preselected:        strings.Contains(installset, "base"),
				Path:               filepath.ToSlash(filepath.Join(pathPrefix, spec.subdir, entry.Name())),
				Properties:         strings.TrimSpace(doc.Tags["properties"]),
				Events:             strings.TrimSpace(doc.Tags["events"]),
				GUID:               strings.TrimSpace(doc.Tags["guid"]),
				Category:           strings.TrimSpace(doc.Tags["modx_category"]),
				LegacyNames:        strings.TrimSpace(doc.Tags["legacy_names"]),
				Method:             "inline",
			}
			if disabled := strings.TrimSpace(doc.Tags["disabled"]); disabled != "" {
				pkg.Disabled = disabled == "1" || strings.EqualFold(disabled, "true")
			}
			if share := strings.TrimSpace(doc.Tags["shareparams"]); share != "" {
				if n, err := strconv.Atoi(share); err == nil {
					pkg.ShareParams = n
				}
			}
			if icon := strings.TrimSpace(doc.Tags["icon"]); icon != "" {
				pkg.Icon = icon
			}
			pkgs = append(pkgs, pkg)
		}
	}
	return pkgs, nil
}

func parseExtrasDocblockFile(path string) (extrasDocblock, error) {
	f, err := os.Open(path)
	if err != nil {
		return extrasDocblock{}, err
	}
	defer f.Close()

	scanner := bufio.NewScanner(f)
	doc := extrasDocblock{Tags: map[string]string{}}
	inDocblock := false
	foundName := false
	foundDesc := false

	for scanner.Scan() {
		line := scanner.Text()
		if !inDocblock {
			if strings.Contains(line, "/**") {
				inDocblock = true
			}
			continue
		}
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "*/") {
			break
		}
		if !strings.HasPrefix(trimmed, "*") {
			continue
		}
		body := strings.TrimSpace(strings.TrimPrefix(trimmed, "*"))
		if body == "" {
			continue
		}
		if strings.HasPrefix(body, "@") {
			tagLine := body[1:]
			tag, value, _ := strings.Cut(tagLine, " ")
			tag = strings.TrimSpace(tag)
			value = strings.TrimSpace(value)
			if tag == "internal" && strings.HasPrefix(value, "@") {
				tagLine = value[1:]
				tag, value, _ = strings.Cut(tagLine, " ")
				tag = strings.TrimSpace(tag)
				value = strings.TrimSpace(value)
			}
			if tag != "" && value != "" {
				doc.Tags[tag] = value
			}
			continue
		}
		if !foundName {
			doc.Name = body
			foundName = true
			continue
		}
		if !foundDesc {
			doc.Description = body
			foundDesc = true
		}
	}
	if err := scanner.Err(); err != nil {
		return extrasDocblock{}, err
	}
	return doc, nil
}

func loadLegacyStoreCatalog(ctx context.Context) ([]domain.ExtrasPackage, error) {
	body := bytes.NewBufferString("get=start&user=1&lang=en")
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, legacyStoreCatalogURL, body)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "EvolutionCMS-Installer/Go")

	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("unexpected status %s", resp.Status)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
	if err != nil {
		return nil, err
	}
	return parseLegacyStoreCatalogJSON(raw)
}

func parseLegacyStoreCatalogJSON(raw []byte) ([]domain.ExtrasPackage, error) {
	var payload legacyStoreStartResponse
	if err := json.Unmarshal(raw, &payload); err != nil {
		return nil, err
	}

	categories := map[string]string{}
	for _, cat := range payload.Category {
		id := strings.TrimSpace(string(cat.ID))
		if id != "" {
			categories[id] = strings.TrimSpace(cat.Title)
		}
	}

	out := make([]domain.ExtrasPackage, 0, 256)
	for categoryID, items := range payload.AllCategory {
		section := strings.TrimSpace(categories[categoryID])
		if section == "" {
			section = "Legacy Store"
		}
		for _, item := range items {
			name := strings.TrimSpace(item.NameInMODX)
			if name == "" {
				name = strings.TrimSpace(item.Title)
			}
			if name == "" {
				continue
			}

			versions := make([]string, 0, len(item.URL.FieldValue))
			downloadURL := ""
			version := ""
			for _, candidate := range item.URL.FieldValue {
				v := strings.TrimSpace(candidate.Version)
				u := strings.TrimSpace(candidate.File)
				if v != "" {
					versions = append(versions, v)
				}
				if downloadURL == "" && u != "" {
					downloadURL = u
				}
				if version == "" && v != "" {
					version = v
				}
			}

			out = append(out, domain.ExtrasPackage{
				ID:                 "legacy-store:" + strings.TrimSpace(string(item.ID)),
				Name:               name,
				Version:            version,
				Versions:           versions,
				Description:        strings.TrimSpace(item.Description),
				DefaultInstallMode: "legacy-store-zip",
				Source:             "legacy-store",
				Section:            "Legacy Store - " + section,
				Kind:               strings.TrimSpace(item.Type),
				InstallMode:        "legacy-store-zip",
				DownloadURL:        downloadURL,
				Dependencies:       strings.TrimSpace(item.Dependencies),
				Deprecated:         strings.TrimSpace(item.Deprecated) == "1",
				Method:             strings.TrimSpace(item.Method),
			})
		}
	}
	return sanitizeExtrasPackages(out), nil
}
