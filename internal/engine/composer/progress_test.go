package composer

import "testing"

func TestMapper_PhasesAndCaps(t *testing.T) {
	m := NewMapper()

	if p, ok := m.Observe("Loading composer repositories with package information"); !ok || p != 5 {
		t.Fatalf("loading repos => (%d,%v), want (5,true)", p, ok)
	}
	if p, ok := m.Observe("Reading ./composer.json"); !ok || p != 8 {
		t.Fatalf("reading composer.json => (%d,%v), want (8,true)", p, ok)
	}
	if p, ok := m.Observe("Updating dependencies"); !ok || p != 15 {
		t.Fatalf("updating deps => (%d,%v), want (15,true)", p, ok)
	}
	if p, ok := m.Observe("Resolving dependencies through SAT"); !ok || p != 20 {
		t.Fatalf("resolving deps => (%d,%v), want (20,true)", p, ok)
	}
	if p, ok := m.Observe("Writing lock file"); !ok || p != 35 {
		t.Fatalf("writing lock => (%d,%v), want (35,true)", p, ok)
	}
	if p, ok := m.Observe("Package operations: 12 installs, 0 updates, 0 removals"); !ok || p != 45 {
		t.Fatalf("package ops => (%d,%v), want (45,true)", p, ok)
	}

	if p, ok := m.Observe("Downloading symfony/console (v7.0.0)"); ok || p != 0 {
		t.Fatalf("first downloading should not update when already past min, got (%d,%v)", p, ok)
	}
	// First download line should ensure at least 30, but we are already at 45.
	// Subsequent downloading should climb by +1 up to 60.
	last := 45
	for i := 0; i < 50; i++ {
		p, ok := m.Observe("Downloading vendor/package")
		if ok {
			if p < last {
				t.Fatalf("progress decreased: %d -> %d", last, p)
			}
			last = p
		}
	}
	if last != 60 {
		t.Fatalf("downloading should cap at 60, got %d", last)
	}

	// Installing starts at least 60, then climbs to 85.
	if p, ok := m.Observe("Installing vendor/package"); ok || p != 0 {
		t.Fatalf("first installing should not update when already at min, got (%d,%v)", p, ok)
	}
	last = 60
	for i := 0; i < 50; i++ {
		p, ok := m.Observe("Installing vendor/package")
		if ok {
			if p < last {
				t.Fatalf("progress decreased: %d -> %d", last, p)
			}
			last = p
		}
	}
	if last != 85 {
		t.Fatalf("installing should cap at 85, got %d", last)
	}

	if p, ok := m.Observe("Generating autoload files"); !ok || p != 90 {
		t.Fatalf("autoload => (%d,%v), want (90,true)", p, ok)
	}
}

func TestMapper_ErrorHintsDoNotAdvance(t *testing.T) {
	m := NewMapper()
	m.last = 20
	if p, ok := m.Observe("Permission denied"); ok || p != 0 {
		t.Fatalf("error hint should not advance, got (%d,%v)", p, ok)
	}
}

func TestMapper_DownloadingFromCache(t *testing.T) {
	m := NewMapper()
	if p, ok := m.Observe("Downloading something from cache"); !ok || p != 55 {
		t.Fatalf("downloading from cache => (%d,%v), want (55,true)", p, ok)
	}
}
