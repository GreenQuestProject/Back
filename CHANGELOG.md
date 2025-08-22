# Changelog (Backend)
Toutes les modifications notables du **backend Symfony/API** sont listées ici (SemVer).

## [Unreleased] - 2025-08-22
_A regrouper dans la prochaine version._

## [0.0.1] - 2025-04-02
> Périmètre : 11 commits (du 2025-03-15 au 2025-04-02).
### Modifié
- Init project (ee0ef65)
- Add bundles for api (5ccd397)
- Ajout du workflow GitHub Actions pour le backend (4af3bb0)
- Create User entity (7ff439c)
- CRUD User + Tests (3e3796b)
- Finishing CRUD User + Tests (eb90a7e)
- Update composer.lock file (6e07f2c)
- Update composer.lock file (dcea914)
- Update backend.yml file (1cfa92c)
- Update backend.yml file (87b175e)
- Update backend.yml file (02e7800)

---

## [0.0.2] - 2025-04-14
> Périmètre : 11 commits (du 2025-04-02 au 2025-04-14).
### Modifié
- Update backend.yml file (fafb498)
- Update backend.yml file (ad6b622)
- Update backend.yml file (9b5430b)
- Update backend.yml file (30ef486)
- Update backend.yml file (24af7cc)
- Update backend.yml file (0093b4f)
- Update backend.yml file (9068b3c)
- Update backend.yml file (204f9aa)
- Update backend.yml file (5b43062)
- Update backend.yml file (8fe925b)
- Update backend.yml file (043e5e7)

---

## [0.0.3] - 2025-05-05
> Périmètre : 11 commits (du 2025-04-14 au 2025-05-05).
### Modifié
- Update backend.yml and test files (04f03b2)
- Update test file (bceffef)
- Update test file (b39d1c3)
- Update yml file (27482d4)
- Update yml file (1061350)
- Adding CORS bundle (8d72abd)
- Init Dockerfile (1731141)
- Reogranizing UserController and tests (c32e40b)
- Challenge entity + controller + tests (439f1a0)
- Challenge entity (d99b409)
- Update Challenge tests (773aca8)

---

## [0.0.4] - 2025-05-09
> Périmètre : 11 commits (du 2025-05-05 au 2025-05-09).
### Modifié
- Progression entity (a2bd7a4)
- Progression relation with User (7708c9c)
- Routes for starting, remove, validate challenge, get user’s progression list (9839314)
- Adding progression test (dbf41f3)
- Configuring deploy (fdb42d1)
- Config cors deploy (344e3a1)
- Update Dockerfile after failed deploy (e453cd7)
- Refacto workflow (de39a70)
- Refacto workflow (a4b615e)
- Refacto workflow (186cf57)
- Fix workflow (5a749c8)

---

## [0.0.5] - 2025-05-11
> Périmètre : 11 commits (du 2025-05-09 au 2025-05-11).
### Modifié
- Fix workflow (7af8601)
- Fix workflow (246c954)
- Fix nginx conf (bb24133)
- Manage cors when prod (4b8405f)
- Fix nginx conf (5c3ee36)
- Allow origin for server (aeaf18b)
- Fix Dockerfile (ca2080d)
- Create db and passing jwt keys to container (d307291)
- Whait docker container to be up (28f81e1)
- Split JWT and update db in other step (e48ae79)
- Split JWT and update db in other step (53e1534)

---

## [0.0.6] - 2025-06-26
> Périmètre : 11 commits (du 2025-05-11 au 2025-06-26).
### Modifié
- Split JWT and update db in other step (f0b820c)
- Split JWT and update db in other step (78599f3)
- Split JWT and update db in other step (5148020)
- Split JWT and update db in other step (14bfee3)
- Split JWT and update db in other step (a7f39ac)
- Progression Controller tests (561985a)
- Adding data fixtures for Progression and Challenge entity (7cc17be)
- Update User and Progression controllers tests (20d4555)
- Adding filters in challenge list (163a5e8)
- Allow only admin for accessing doc api (b170cc3)
- Fix filter on category's challenges + adding isInUserProgression bool (d19fe6f)

---

## [0.0.7] - 2025-08-16
> Périmètre : 11 commits (du 2025-06-26 au 2025-08-16).
### Sécurité
- Fix weak cryptography security hotspots (93cba67)

### Modifié
- Fix filter on category's challenges + status's progressions (b221267)
- Adding new route for status progression update (e265248)
- Update nelmio_cors.yaml (1377d6c)
- Implementing jwt refresh token (571f523)
- Update CI/CD (095931d)
- Update CI/CD (795f409)
- Update Dockerfile and nginx conf (6cdc552)
- Update CI/CD (3037186)
- Update CI/CD (c0b3d7f)
- Update tests (393792b)

---

## [0.0.8] - 2025-08-21
> Périmètre : 11 commits (du 2025-08-16 au 2025-08-21).
### Sécurité
- Fix security hotspot (81d89e5)

### Modifié
- Update CI/CD (209b174)
- Allow cors (1a254d5)
- Update CI/CD (24df889)
- Update CI/CD (337283f)
- Update CI/CD (9d40531)
- New routes for getting enums (challenge category and status) (621af05)
- Translating error messages (0e6c2a5)
- Update CI/CD + adding fixtures (055efa5)
- Delete health category (9fe0383)
- Update composer packages (333d26e)

## [0.0.9] - 2025-08-22
### Maintenance
- Mise en production **sans changement fonctionnel** (redeploy/infra uniquement).
### Breaking changes / Migrations
- Aucune.
### Traçabilité
- Tag : `v0.0.9`

---
