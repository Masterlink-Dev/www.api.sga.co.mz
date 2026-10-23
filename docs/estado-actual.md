# MeuAcesso · O que já funciona (22 out 2026)

Resumo do que está ligado à base de dados, pela ordem do fluxo **Super administrador → Administrador → Secretaria**.
Para cada passo: o que faz, a rota da API e as tabelas onde fica gravado.

Todas as rotas começam por `/api`. Depois do login, enviam o cabeçalho `Authorization: Bearer <token>`.
O detalhe de cada rota (o que recebe e o que devolve) está comentado em `routes/api.php`.

---

## 0. Comum a todas as contas

| O quê | Rota | Tabelas |
|---|---|---|
| Entrar (email ou telefone + senha) | `POST /login` | `personal_access_tokens` (o token), `registos_acesso` (cada tentativa, certa ou errada), `users` (`ultimo_acesso_em`, `primeiro_acesso_em`) |
| Saber quem está dentro (ao recarregar a página) | `GET /me` | `users` |
| Mudar a senha | `PUT /senha` | `users` (`password`, `deve_alterar_senha = false`, `senha_alterada_em`), `registos_acesso` |
| Sair | `POST /logout` | apaga o token em `personal_access_tokens` |

- O login devolve o `tipo` (0 app, 1 funcionário, 2 administrador, 3 super administrador) e a `area` (`app`, `portal`, `admin`, `superadmin`). O portal usa a `area` para abrir a página certa.
- **Senha inicial:** todas as contas novas nascem com `00000000` e `deve_alterar_senha = true`. Enquanto for assim, o middleware `senha.alterada` responde `403 SENHA_INICIAL` a tudo, excepto `PUT /senha`, `GET /me` e `GET /secretaria/me`.
- **Áreas:** cada área só aceita o seu tipo de conta (middleware `tipo:N`). Qualquer outro tipo recebe `403 SEM_PERMISSAO`.

---

## 1. Super administrador (tipo 3)

A primeira conta é criada pelo seeder `DadosBaseSeeder`, com `SUPERADMIN_EMAIL` e `SUPERADMIN_SENHA` do `.env`.

| O quê | Rota | Tabelas |
|---|---|---|
| Criar um administrador (só o email) | `POST /superadmin/administradores` | `users` (nova conta, `tipo = 2`, senha inicial, `criado_por`), `envios_credenciais` (o email enviado), `registos_auditoria` |
| Lista de administradores (ecrã «Credenciais») | `GET /superadmin/administradores` | lê `users` + `instituicoes` (para saber se já registou a instituição) |
| Repor a senha de um administrador | `POST /superadmin/administradores/{id}/redefinir-senha` | `users` (volta a `00000000`), apaga os tokens dele, `registos_auditoria`. **Não envia email.** |
| Painel da plataforma | `GET /superadmin/painel` | lê `instituicoes`, `users`, `funcionarios`, `visitas`, `tipos_instituicao` |
| Instituições registadas (só consulta) | `GET /superadmin/instituicoes` | lê `instituicoes`, `horarios_atendimento`, `funcionarios`, `visitas` |
| Utilização: app móvel e portal web | `GET /superadmin/utilizacao` | lê `users` (por tipo e data de criação), `visitas` + `cidadaos` |
| Relatórios (período, tipo, instituição) | `GET /superadmin/relatorios` | lê `visitas`, `instituicoes`, `funcionarios`. Exportar PDF/Excel ainda responde `501`. |

**Email das credenciais:** só um, quando a conta é criada. Leva o endereço do portal (`PORTAL_URL`), o email e a senha inicial. Com `MAIL_MAILER=log` fica escrito em `storage/logs/laravel.log`.

---

## 2. Administrador da instituição (tipo 2)

Depois de mudar a senha, o administrador encontra o ambiente vazio. Tudo o que regista fica ligado à **sua** instituição: o sistema descobre-a pela conta, em `instituicoes.administrador_id`, e nunca a lê do pedido. Os passos 2.2 a 2.5 só funcionam depois de a instituição estar registada; antes disso respondem `409 SEM_INSTITUICAO`.

### 2.1 Registar a instituição (3 passos)

| O quê | Rota |
|---|---|
| Ver os dados (vazios se ainda não registou) | `GET /admin/instituicao` |
| Registar (1.ª vez) ou actualizar | `PUT /admin/instituicao` |
| Logótipo em ficheiro, como alternativa | `POST /admin/instituicao/logo` |

Onde fica cada parte do formulário:

| Passo do portal | Campo | Tabela · coluna |
|---|---|---|
| Identificação | Nome, sigla, NUIT, descrição | `instituicoes` · `nome`, `sigla`, `nuit` (só algarismos), `descricao` |
| | Tipo («Ministério»…) | `instituicoes.tipo_instituicao_id` → `tipos_instituicao` |
| | Logótipo | ficheiro em `storage/app/private/instituicoes/<uuid>/`, uma linha em `ficheiros` (`categoria = logotipo`) e `instituicoes.logo_ficheiro_id` |
| Contactos | Província | `instituicoes.provincia_id` → `provincias` |
| | Endereço, telefone, email | `instituicoes` · `endereco`, `telefone`, `email` |
| Horário | Abertura, fecho e dias | `horarios_atendimento`: **uma linha por dia escolhido** (`dia_semana` 1 = segunda … 7 = domingo, `abre_as`, `fecha_as`) |

Na primeira gravação o sistema preenche ainda:
- `instituicoes`: `administrador_id`, `slug` (a partir da sigla), `estado = activa` e `registada_em`;
- `definicoes_instituicao`: as regras de privacidade e de notificações, com os valores por omissão;
- `formularios`: o formulário de visita da instituição, ainda sem campos.

### 2.2 Sectores e motivos

| O quê | Rota |
|---|---|
| Ver as duas listas | `GET /admin/sectores` |
| Criar sector `{ nome, piso, responsavel }` | `POST /admin/sectores` |
| Editar, ligar ou desligar um sector | `PUT /admin/sectores/{id}` |
| Remover sector | `DELETE /admin/sectores/{id}` |
| Acrescentar motivo `{ nome }` | `POST /admin/motivos` |
| Retirar motivo | `DELETE /admin/motivos/{nome}` |

**Tabela `sectores`:** uma linha por sector, com `instituicao_id`, `nome`, `piso`, `responsavel_nome`, `activo` e `ordem`.
- Exemplo: o administrador cria «Farmácia», piso «R/C». Fica uma linha com `nome = Farmácia`, `piso = R/C` e `activo = 1`.
- Desligar o sector põe `activo = 0`: deixa de aparecer na recepção, mas continua na lista do administrador.

**Tabela `motivos_visita`:** uma linha por motivo.
- Os motivos da instituição têm o `instituicao_id` dela.
- O motivo **«Outro»** é da plataforma (`instituicao_id = NULL`, `e_outro = 1`). Existe em todas as instituições, fica sempre no fim da lista e não se pode retirar. Quando é escolhido, a recepção escreve o motivo por extenso.

**Remover:** sectores e motivos removidos não são apagados. A coluna `deleted_at` fica preenchida, e assim as visitas antigas continuam a mostrar o nome. Se se voltar a criar um com o mesmo nome, a linha antiga é recuperada, porque o nome é único por instituição.

### 2.3 Formulário de visita (campos dinâmicos)

| O quê | Rota |
|---|---|
| Ver os campos | `GET /admin/formulario/campos` |
| Criar campo `{ nome, tipo, obrigatorio?, opcoes? }` | `POST /admin/formulario/campos` |
| Mudar obrigatório, visível, nome ou opções | `PUT /admin/formulario/campos/{id}` |
| Remover campo | `DELETE /admin/formulario/campos/{id}` |

São quatro tabelas, ligadas assim:

```
formularios (1 por instituição)
   └── campos_formulario (os campos que o administrador cria)
          └── opcoes_campo (as opções das listas escritas à mão)

respostas_visita (o que a recepção preencher em cada visita — ainda não está a ser gravado, ver ponto 3)
```

**`formularios`:** um por instituição (`finalidade = registo_visita`). A `versao` sobe sempre que um campo muda.

**`campos_formulario`:** uma linha por campo.

| Coluna | Para que serve |
|---|---|
| `nome` | O que a recepção lê («Vem acompanhado?») |
| `chave` | Identificador estável usado na API (`vem_acompanhado`) |
| `tipo` | Código do tipo. O portal mostra o rótulo: Texto curto → `texto_curto`, Lista de escolha → `lista`, BI → `bi`, Telefone → `telefone`, Data → `data`, Número → `numero`, Texto longo → `texto_longo` |
| `campo_sistema` | Se preenchido, a resposta vai para uma **coluna da visita** e não para `respostas_visita` (ver tabela abaixo) |
| `fonte_opcoes` | Só nas listas: de onde vêm as opções. `motivos` → tabela `motivos_visita`, `sectores` → tabela `sectores`, `manual` → tabela `opcoes_campo` |
| `obrigatorio`, `visivel`, `ordem` | As regras que o administrador liga e desliga. Um campo oculto deixa de ser obrigatório automaticamente |

O sistema reconhece estes campos pelo nome e pelo tipo, e liga-os às colunas da visita:

| O administrador cria… | `campo_sistema` | A resposta vai para… |
|---|---|---|
| Lista com «motivo» no nome | `motivo` | `visitas.motivo_id` (opções de `motivos_visita`) |
| Lista com «sector» no nome | `sector` | `visitas.sector_id` (opções de `sectores`) |
| Tipo BI | `documento_numero` | `visitas.documento_numero` |
| Tipo Telefone | `telefone_visitante` | `visitas.telefone_visitante` |
| «Pessoa a visitar» | `pessoa_a_visitar` | `visitas.pessoa_a_visitar` |
| «Observações» | `observacoes` | `visitas.observacoes` |
| Qualquer outro | *(vazio)* | `respostas_visita`, uma linha por campo |

**`opcoes_campo`:** as opções das listas próprias, uma linha por opção (`valor`, `rotulo`, `ordem`).

**Exemplos do que fica gravado:**
- **«Vem acompanhado?», Lista de escolha, opções «Sim, Não»**
  - `campos_formulario`: `chave = vem_acompanhado`, `tipo = lista`, `campo_sistema = NULL`, `fonte_opcoes = manual`.
  - `opcoes_campo`: duas linhas, `sim/Sim` e `nao/Não`.
- **«Motivo da visita», Lista de escolha**
  - `campos_formulario`: `chave = motivo`, `campo_sistema = motivo`, `fonte_opcoes = motivos`.
  - Não grava nada em `opcoes_campo`: as opções são as de «Sectores e motivos».
- **Remover um campo:** preenche `deleted_at`, como nos sectores. Se se voltar a criar o mesmo campo, a linha antiga é recuperada.

### 2.4 Equipa

| O quê | Rota | Tabelas |
|---|---|---|
| Lista da equipa | `GET /admin/equipa` | lê `funcionarios` + `users` |
| Registar funcionário `{ nome, email, perfil }` | `POST /admin/equipa` | `users` (nova conta `tipo = 1`, senha inicial), `funcionarios` (ligação à instituição), `envios_credenciais`, `registos_auditoria` |
| Suspender / reactivar / mudar função | `PUT /admin/equipa/{id}` | `funcionarios.estado` (`activo` / `suspenso`), `funcionarios.cargo`; ao suspender apaga os tokens da pessoa |

**`funcionarios`:** liga a conta à instituição, com as colunas `user_id`, `instituicao_id`, `cargo` (Secretária, Recepcionista ou Funcionária), `perfil_id`, `origem`, `registado_por`, `estado` e `aprovado_em`.
- `perfil_id` aponta para `perfis` (os perfis da plataforma criados pelo seeder).
- `origem` diz como a pessoa entrou: `administrador` (registada pelo administrador) ou `pedido_colega` (vinda de um pedido aprovado).

### 2.5 Pedidos de colegas

| O quê | Rota | Tabelas |
|---|---|---|
| Pedidos pendentes | `GET /admin/pedidos` | lê `pedidos_funcionario` |
| Aprovar | `POST /admin/pedidos/{id}/aprovar` | cria em `users` e `funcionarios` (`origem = pedido_colega`) e envia o email (`envios_credenciais`); o pedido fica `estado = aprovado`, com `decidido_por`, `decidido_em` e `funcionario_id` |
| Recusar `{ motivo? }` | `POST /admin/pedidos/{id}/recusar` | `pedidos_funcionario`: `estado = recusado`, `motivo_recusa` |

### 2.6 Painel do administrador

`GET /admin/painel` devolve:
- as visitas de hoje e quem está dentro agora;
- o número de funcionários e de pedidos pendentes;
- os 5 passos concluídos (senha, instituição, sectores, formulário, equipa);
- as visitas por sector.

É tudo calculado a partir das tabelas acima.

---

## 3. Secretaria / funcionária (tipo 1) — ponto de amanhã

**Já funciona:**

| O quê | Rota | Tabelas |
|---|---|---|
| Quem sou + sectores e motivos activos | `GET /secretaria/me` | lê `funcionarios`, `instituicoes`, `sectores`, `motivos_visita` |
| Propor um colega `{ nome, email, bi?, cargo? }` | `POST /secretaria/colegas` | `pedidos_funcionario` (`estado = pendente`, `proposto_por`) |
| Ver os colegas que propôs | `GET /secretaria/colegas` | lê `pedidos_funcionario` |

**Rotas que já existiam antes (não voltaram a ser testadas):** `POST /secretaria/visitas` (entrada pelo código ou QR), `POST /secretaria/visitas/manual` (pelo BI físico), `POST /secretaria/saida`, `GET /secretaria/visitas`, `/visitantes`, `/dentro-agora`, `/resumo` e `/cidadaos/{numero}`. Gravam e lêem nas tabelas `visitas`, `cidadaos`, `consultas_dados` e `restricoes_acesso`.

**Falta (ponto 3):**
1. O ecrã «Registar entrada» tem de **desenhar o formulário dinâmico** a partir de `campos_formulario` (hoje tem campos fixos), e a API tem de **gravar as respostas**:
   - os campos com `campo_sistema` vão para as colunas de `visitas`;
   - os outros vão para `respostas_visita`, uma linha por campo, com uma cópia do nome e do tipo;
   - a API tem de validar os obrigatórios.
2. Ecrã para a funcionária propor colegas, ligado a `/secretaria/colegas`.
3. Retirar os dados de exemplo que ainda restam na recepção: Relatórios, Utilizadores e Definições usam `src/data/mock/`.
