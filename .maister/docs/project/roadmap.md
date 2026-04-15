# Modernization Roadmap

## Current State Assessment
- **Version**: phpBB 3.3.15
- **Technology Age**: Symfony 3.4 (EOL), Twig 1.x/2.x, PHP 7.2+ baseline
- **Technical Debt**: High — procedural legacy layer, no strict typing, no CI/CD, no static analysis
- **Architecture**: Hybrid monolith in active modernization (legacy `includes/` + modern `phpbb/`)
- **Test coverage**: Test suite not co-located (upstream-only), limited local feedback loop
- **Developer**: Solo

---

## Modernization Goals

### Critical (Must Do)

- [ ] **Upgrade Symfony 3.4 → 6.x/7.x LTS** — Symfony 3.4 is EOL with known CVEs; upgrade is essential for security and long-term maintenance `Risk: High if delayed`
- [ ] **Upgrade Twig 1.x/2.x → 3.x** — Required by Symfony 6+ twig-bridge; offers better security sandboxing and performance `Risk: Medium`
- [ ] **Add GitHub Actions CI pipeline** — Lint + test + `composer audit` on every commit; prevents regression and security drift `Effort: S`
- [ ] **Add `composer audit` to development workflow** — Identify vulnerable dependency versions (Guzzle 6.3, Symfony 3.4 components) `Effort: XS`

### Important (Should Do)

- [ ] **Add PHPStan at level 5** — Replace PHP_CodeSniffer-only workflow with static analysis to surface typing errors and logic bugs `Effort: M`
- [ ] **Bring test suite into repository** — Add `tests/` directory with PHPUnit configuration for local test runs; currently tests live only in upstream `Effort: M`
- [ ] **Raise PHP minimum to 8.1** — Enables readonly properties, enums, fibers, and first-class callable syntax; align with Symfony 6.x requirements `Effort: M`
- [ ] **Introduce PHP 8.x type hints in `phpbb/` layer** — Union types, named arguments, readonly properties for all new code and refactored services `Effort: L`

### Improvements (Nice to Do)

- [ ] **Reduce `global` variable usage in `includes/`** — Replace `global $db, $config, $user` with injected DI services; improve testability `Effort: L`
- [ ] **Replace PHP_CodeSniffer with PHP-CS-Fixer** — More modern, auto-fixing formatter; align with PSR-12 `Effort: S`
- [ ] **Add Rector for automated refactoring** — Automate PHP 8.x syntax upgrades across legacy code `Effort: M`
- [ ] **Evaluate minimal frontend tooling** — Consider Alpine.js or HTMX for interactive UI improvements without full SPA complexity `Effort: L`
- [ ] **Add OpenAPI documentation** — Document `app.php` routes for extension developers `Effort: M`
- [ ] **Dockerize development environment** — Replace Homestead/Vagrant with a `docker-compose.yml` for reproducible local setup `Effort: S`

---

## Migration Strategy

### Symfony 3.4 → 6.x/7.x Upgrade Path

1. **Audit deprecations**: Run `symfony/phpunit-bridge` deprecation listener to identify all deprecated 3.4 API usages
2. **Upgrade to Symfony 4.4 LTS** as intermediate step (maintaining BC) with deprecation fixes
3. **Upgrade to Symfony 5.4 LTS** — resolve any remaining deprecations
4. **Upgrade to Symfony 6.x/7.x** — PHP 8.1 required; finalize strict typing migration
5. Run full test suite at each step before proceeding

### PHP 7.2 → 8.1 Path

1. Run `composer require --dev phpstan/phpstan` and fix all level-1 errors first
2. Gradually raise PHPStan level (1 → 3 → 5) with each code cleanup iteration
3. Replace `var` property declarations in `includes/acp/`, `includes/mcp/`, `includes/ucp/`
4. Add return types and parameter types to `phpbb/` classes
5. Add `declare(strict_types=1)` to new files in `phpbb/`

---

## Risk Mitigation

- **Incremental approach**: Upgrade one major component at a time; run `composer audit` and PHPStan after each
- **Feature flags**: Use phpBB's config system to gate experimental changes
- **Backward compatibility**: Keep `includes/` functional throughout migration — it's the production-critical path
- **Test before upgrading**: Establish local test baseline before any framework upgrade begins

---

*Assessment based on project analysis performed April 2026*
*Effort Scale*: `XS` < 1 day | `S` 2-3 days | `M` 1 week | `L` 2+ weeks
