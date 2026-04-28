package install

import (
	"strings"
	"testing"

	"github.com/evolution-cms/installer/internal/services/github"
)

func TestSanitizeAdminDir(t *testing.T) {
	t.Parallel()

	cases := []struct {
		in   string
		want string
	}{
		{"", "manager"},
		{"   ", "manager"},
		{"manager", "manager"},
		{" admin ", "admin"},
		{"my-admin_dir", "my-admin_dir"},
		{"../admin", "admin"},
		{"менеджер", "manager"},
		{"my admin dir", "myadmindir"},
	}

	for _, tc := range cases {
		if got := sanitizeAdminDir(tc.in); got != tc.want {
			t.Fatalf("sanitizeAdminDir(%q)=%q; want %q", tc.in, got, tc.want)
		}
	}
}

func TestParseVersionForCompare(t *testing.T) {
	t.Parallel()

	maj, min, patch, ok := parseVersionForCompare("v1.2.3")
	if !ok || maj != 1 || min != 2 || patch != 3 {
		t.Fatalf("parseVersionForCompare(v1.2.3)=(%d,%d,%d,%v); want (1,2,3,true)", maj, min, patch, ok)
	}

	maj, min, patch, ok = parseVersionForCompare("1.2.3-rc1")
	if !ok || maj != 1 || min != 2 || patch != 3 {
		t.Fatalf("parseVersionForCompare(1.2.3-rc1)=(%d,%d,%d,%v); want (1,2,3,true)", maj, min, patch, ok)
	}

	_, _, _, ok = parseVersionForCompare("dev")
	if ok {
		t.Fatalf("parseVersionForCompare(dev)=ok; want !ok")
	}
}

func TestCmpSemver(t *testing.T) {
	t.Parallel()

	if got := cmpSemver(1, 2, 3, 1, 2, 3); got != 0 {
		t.Fatalf("cmpSemver equal=%d; want 0", got)
	}
	if got := cmpSemver(1, 2, 4, 1, 2, 3); got <= 0 {
		t.Fatalf("cmpSemver greater=%d; want >0", got)
	}
	if got := cmpSemver(1, 2, 2, 1, 2, 3); got >= 0 {
		t.Fatalf("cmpSemver less=%d; want <0", got)
	}
}

func TestDbConnectionTestScriptUsesPostgresMaintenanceDb(t *testing.T) {
	t.Parallel()

	if !strings.Contains(dbConnectionTestScript, `"postgres"`) || !strings.Contains(dbConnectionTestScript, `"template1"`) {
		t.Fatalf("dbConnectionTestScript does not include expected PostgreSQL maintenance database candidates")
	}
}

func TestProjectPresetOptionsFromReposDefaultsToCoreThenCustom(t *testing.T) {
	t.Parallel()

	options, selected := projectPresetOptionsFromRepos([]github.GitHubRepository{
		{Name: "portfolio", FullName: "evolution-cms-presets/portfolio", Description: "Portfolio starter"},
		{Name: "default-daisyui", FullName: "evolution-cms-presets/default-daisyui"},
		{Name: "default", FullName: "evolution-cms-presets/default", Description: "Default preset"},
		{Name: "default-tailwind", FullName: "evolution-cms-presets/default-tailwind"},
		{Name: "archived", FullName: "evolution-cms-presets/archived", Archived: true},
	})

	wantIDs := []string{
		projectPresetCoreOnlyID,
		projectPresetCustomID,
		"evolution-cms-presets/default",
		"evolution-cms-presets/default-tailwind",
		"evolution-cms-presets/default-daisyui",
		"evolution-cms-presets/portfolio",
	}
	if len(options) != len(wantIDs) {
		t.Fatalf("got %d options, want %d: %#v", len(options), len(wantIDs), options)
	}
	for i, want := range wantIDs {
		if options[i].ID != want {
			t.Fatalf("option[%d]=%q, want %q", i, options[i].ID, want)
		}
		if !options[i].Enabled {
			t.Fatalf("option[%d] should be enabled", i)
		}
	}
	if selected != 0 {
		t.Fatalf("selected=%d, want 0", selected)
	}
	if options[0].ID != projectPresetCoreOnlyID {
		t.Fatalf("default option should be core-only, got %q", options[0].ID)
	}
	if !strings.Contains(options[2].Label, "Default preset") {
		t.Fatalf("default label does not include description: %q", options[2].Label)
	}
}

func TestFallbackProjectPresetOptionsIncludesCustomAndCoreOnly(t *testing.T) {
	t.Parallel()

	options, selected := fallbackProjectPresetQuestionOptions()
	if selected != 0 {
		t.Fatalf("selected=%d, want 0", selected)
	}
	if len(options) < 5 {
		t.Fatalf("expected fallback presets plus special options, got %#v", options)
	}
	if options[0].ID != projectPresetCoreOnlyID {
		t.Fatalf("expected core-only option first, got %#v", options)
	}
	if options[1].ID != projectPresetCustomID {
		t.Fatalf("expected custom option second, got %#v", options)
	}
}
