.PHONY: dev install doctor version logo logo-clean

dev: install

install:
	go run ./cmd/evo install

doctor:
	go run ./cmd/evo doctor

version:
	go run ./cmd/evo version

logo:
	@command -v rsvg-convert >/dev/null || (echo "Missing rsvg-convert (install: sudo apt install librsvg2-bin)" && exit 1)
	@command -v chafa >/dev/null || (echo "Missing chafa (install: sudo apt install chafa)" && exit 1)
	rsvg-convert internal/ui/assets/evo3.5.svg -w 120 -o /tmp/evo-logo.png
	chafa -s 120x20 /tmp/evo-logo.png > internal/ui/assets/logo.txt
	@echo "Generated internal/ui/assets/logo.txt"

logo-clean:
	rm -f /tmp/evo-logo.png
