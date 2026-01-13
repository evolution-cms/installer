package install

import "testing"

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
