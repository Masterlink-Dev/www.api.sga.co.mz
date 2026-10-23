# Graph Report - www.api.meuacesso.co.mz  (2026-10-22)

## Corpus Check
- Corpus is ~25,052 words - fits in a single context window. You may not need a graph.

## Summary
- 504 nodes · 937 edges · 44 communities (17 shown, 27 thin omitted)
- Extraction: 99% EXTRACTED · 1% INFERRED · 0% AMBIGUOUS · INFERRED: 12 edges (avg confidence: 0.9)
- Token cost: 70,023 input · 0 output

## Community Hubs (Navigation)
- Admin Controllers
- Citizen Document & BI
- Composer Dependencies
- Access Tables Migration
- Secretaria Citizen Lookup
- Secretaria Visit Controller
- Superadmin-Admin Onboarding Flow
- Frontend Build (Vite)
- Secretaria Visitor Controller
- Files, Codes & Tokens Migrations
- Citizen Visit History
- Institutions Migration
- Visits Migration
- Role & Password Middleware
- App Bootstrap
- Citizens Migration
- Support Tables Migration
- Users Table Migration
- Plans Migration
- System Tables Migration
- Feature Test Base
- App Service Provider
- Location Tables Migration
- Reports Migration
- User Number Migration
- BI Data Migration
- Visits Full BI Migration
- Unit Tests
- Laravel README
- Robots.txt

## God Nodes (most connected - your core abstractions)
1. `Controller` - 37 edges
2. `Cidadao` - 27 edges
3. `RotaPrevista` - 24 edges
4. `User` - 17 edges
5. `VisitaController` - 16 edges
6. `LeitorBI` - 14 edges
7. `DocumentoController` - 13 edges
8. `VisitanteController` - 12 edges
9. `HistoricoController` - 11 edges
10. `SectorController` - 10 edges

## Surprising Connections (you probably didn't know these)
- `CidadaoController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/Secretaria/CidadaoController.php → app/Http/Controllers/Controller.php
- `VisitanteController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/Secretaria/VisitanteController.php → app/Http/Controllers/Controller.php
- `DocumentoController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/Utilizador/DocumentoController.php → app/Http/Controllers/Controller.php
- `HistoricoController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/Utilizador/HistoricoController.php → app/Http/Controllers/Controller.php
- `VisitaController` --mixes_in--> `ContextoSecretaria`  [EXTRACTED]
  app/Http/Controllers/Secretaria/VisitaController.php → app/Http/Controllers/Secretaria/ContextoSecretaria.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Administrator onboarding with forced initial password change** — docs_fluxo_superadmin_admin_post_superadmin_administradores, docs_fluxo_superadmin_admin_post_login, docs_fluxo_superadmin_admin_middleware_senha_alterada, docs_fluxo_superadmin_admin_post_admin_senha, docs_fluxo_superadmin_admin_deve_alterar_senha, docs_fluxo_superadmin_admin_redefinir_senha [EXTRACTED 1.00]
- **Admin institution environment setup (instituicao, sectores/motivos, formulario, equipa)** — docs_fluxo_superadmin_admin_admin_instituicao, docs_fluxo_superadmin_admin_admin_sectores_motivos, docs_fluxo_superadmin_admin_admin_formulario_campos, docs_fluxo_superadmin_admin_admin_equipa [EXTRACTED 1.00]
- **Funcionario colleague proposal and admin approval** — docs_fluxo_superadmin_admin_funcionario_role, docs_fluxo_superadmin_admin_secretaria_colegas, docs_fluxo_superadmin_admin_admin_pedidos, docs_fluxo_superadmin_admin_admin_role [EXTRACTED 1.00]

## Communities (44 total, 27 thin omitted)

### Community 0 - "Admin Controllers"
Cohesion: 0.06
Nodes (17): CampoController, EquipaController, InstituicaoController, PainelController, PedidoController, SectorController, SenhaController, RotaPrevista (+9 more)

### Community 1 - "Citizen Document & BI"
Cohesion: 0.07
Nodes (6): DocumentoController, Cidadao, {closure#1}(), {closure#1}(), Ficheiro, LeitorBI

### Community 2 - "Composer Dependencies"
Cohesion: 0.04
Nodes (47): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, dev-master, config (+39 more)

### Community 3 - "Access Tables Migration"
Cohesion: 0.14
Nodes (19): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}() (+11 more)

### Community 4 - "Secretaria Citizen Lookup"
Cohesion: 0.12
Nodes (5): CidadaoController, ContextoSecretaria, User, DatabaseSeeder, SecretariaDemoSeeder

### Community 5 - "Secretaria Visit Controller"
Cohesion: 0.09
Nodes (3): {closure#5}(), {closure#6}(), UserFactory

### Community 6 - "Superadmin-Admin Onboarding Flow"
Cohesion: 0.13
Nodes (20): /api/admin/equipa, /api/admin/formulario/campos, /api/admin/instituicao (+ logo), /api/admin/pedidos/{id}/aprovar|recusar, Administrador (tipo 2), /api/admin/sectores and /admin/motivos, deve_alterar_senha flag / senha inicial 00000000, Fluxo Super administrador -> Administrador -> Equipa (+12 more)

### Community 7 - "Frontend Build (Vite)"
Cohesion: 0.10
Nodes (18): devDependencies, axios, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, private (+10 more)

### Community 8 - "Secretaria Visitor Controller"
Cohesion: 0.17
Nodes (3): {closure#3}(), {closure#8}(), VisitanteController

### Community 9 - "Files, Codes & Tokens Migrations"
Cohesion: 0.13
Nodes (4): {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}()

### Community 11 - "Institutions Migration"
Cohesion: 0.18
Nodes (8): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}()

### Community 12 - "Visits Migration"
Cohesion: 0.22
Nodes (6): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}()

### Community 15 - "Citizens Migration"
Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 16 - "Support Tables Migration"
Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 17 - "Users Table Migration"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 18 - "Plans Migration"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 19 - "System Tables Migration"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

## Knowledge Gaps
- **7 isolated node(s):** `concurrently`, `tailwindcss`, `Laravel Framework`, `MIT License`, `POST /api/login` (+2 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 167 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **27 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Controller` connect `Admin Controllers` to `Citizen Document & BI`, `Secretaria Citizen Lookup`, `Secretaria Visit Controller`, `Secretaria Visitor Controller`, `Citizen Visit History`?**
  _High betweenness centrality (0.049) - this node is a cross-community bridge._
- **What connects `concurrently`, `tailwindcss`, `Laravel Framework` to the rest of the system?**
  _7 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Admin Controllers` be split into smaller, more focused modules?**
  _Cohesion score 0.06163401815575729 - nodes in this community are weakly interconnected._
- **Why does `Cidadao` connect `Citizen Document & BI` to `Secretaria Visitor Controller`, `Citizen Visit History`, `Secretaria Citizen Lookup`?**
  _High betweenness centrality (0.044) - this node is a cross-community bridge._
- **Should `Citizen Document & BI` be split into smaller, more focused modules?**
  _Cohesion score 0.07012987012987013 - nodes in this community are weakly interconnected._
- **Why does `LeitorBI` connect `Citizen Document & BI` to `Secretaria Visitor Controller`?**
  _High betweenness centrality (0.036) - this node is a cross-community bridge._
- **Should `Composer Dependencies` be split into smaller, more focused modules?**
  _Cohesion score 0.041666666666666664 - nodes in this community are weakly interconnected._