# Fluxo Super administrador → Administrador → Equipa

Estado: **rotas previstas** (respondem `501 POR_IMPLEMENTAR` com o que vão fazer e um exemplo do pedido e da resposta).
Já funcionam de verdade apenas duas regras, porque definem o fluxo:

| Regra | Onde | Resultado |
|---|---|---|
| Cada área só aceita o seu tipo de conta | middleware `tipo:N` | `403 SEM_PERMISSAO` |
| Com a senha inicial não se entra no ambiente | middleware `senha.alterada` | `403 SENHA_INICIAL` |

Tipos de conta (`users.tipo`): `0` utilizador da app · `1` funcionário · `2` administrador · `3` super administrador.

## Passo a passo

1. **Super administrador** cria o acesso do administrador: `POST /api/superadmin/administradores` com o **email**.
   A conta fica com `tipo=2`, senha `00000000` e `deve_alterar_senha=true`.
2. **Administrador** entra (`POST /api/login`). A resposta traz `deve_alterar_senha: true`.
   O portal mostra a janela obrigatória e não deixa continuar. A API recusa tudo o resto com `403 SENHA_INICIAL`.
   Se sair sem mudar e voltar, acontece o mesmo.
3. `PUT /api/senha` muda a senha (`deve_alterar_senha=false`). Só depois o ambiente abre — **vazio**.
4. O administrador **regista a própria instituição** (`PUT /api/admin/instituicao`: nome e sigla obrigatórios, logótipo em
   `POST /api/admin/instituicao/logo`), cria sectores e motivos, define o formulário de visita e regista a equipa.
5. Um funcionário pode propor colegas (`POST /api/secretaria/colegas`); o administrador decide em
   `POST /api/admin/pedidos/{id}/aprovar|recusar`. O funcionário só vê os que ele propôs (`GET /api/secretaria/colegas`).
6. O Super administrador **só consulta** as instituições (`GET /api/superadmin/instituicoes`); não as regista.
   Pode redefinir a senha de um administrador (`POST /api/superadmin/administradores/{uuid}/redefinir-senha`).

## Rotas

| Método | Rota | Quem | O que faz |
|---|---|---|---|
| GET | `/superadmin/painel` | 3 | Resumo da plataforma |
| GET | `/superadmin/utilizacao` | 3 | Pessoas que usam a app e o portal |
| GET | `/superadmin/relatorios` | 3 | Indicadores (`?formato=pdf\|xlsx`) |
| GET | `/superadmin/instituicoes` | 3 | Instituições registadas pelos administradores |
| GET | `/superadmin/administradores` | 3 | Credenciais: estado da senha e da instituição |
| POST | `/superadmin/administradores` | 3 | Cria o acesso (email) |
| POST | `/superadmin/administradores/{uuid}/redefinir-senha` | 3 | Repõe 00000000 e volta a obrigar a mudar |
| PUT | `/senha` | todos | Muda a senha (única rota livre com a senha inicial) |
| GET | `/admin/painel` | 2 | Resumo e passos concluídos |
| GET, PUT | `/admin/instituicao` | 2 | Ver / registar / actualizar a instituição |
| POST | `/admin/instituicao/logo` | 2 | Logótipo |
| GET, POST | `/admin/sectores` | 2 | Sectores e motivos |
| PUT, DELETE | `/admin/sectores/{id}` | 2 | Editar / remover sector |
| POST, DELETE | `/admin/motivos`, `/admin/motivos/{nome}` | 2 | Motivos de visita |
| GET, POST | `/admin/formulario/campos` | 2 | Campos do formulário de visita |
| PUT, DELETE | `/admin/formulario/campos/{id}` | 2 | Editar / remover campo |
| GET, POST | `/admin/equipa` | 2 | Listar / registar funcionários |
| PUT | `/admin/equipa/{uuid}` | 2 | Editar / suspender |
| GET | `/admin/pedidos` | 2 | Pedidos dos funcionários |
| POST | `/admin/pedidos/{id}/aprovar`, `/recusar` | 2 | Decidir |
| GET, POST | `/secretaria/colegas` | 1 | Propor colegas / ver os propostos |

## Para a fase seguinte (código a sério)

- Tabelas do fluxo (já criadas nas migrations):
  - `users` — `tipo`, `criado_por`, `deve_alterar_senha`, `primeiro_acesso_em`
  - `instituicoes.administrador_id` — liga a instituição ao administrador que a registou (sem linha = ainda por registar)
  - `horarios_atendimento` — uma linha por dia escolhido no passo "Horário de atendimento"
  - `sectores`, `motivos_visita` (o motivo "Outro" é da plataforma), `definicoes_instituicao`
  - `formularios`, `campos_formulario`, `opcoes_campo`, `respostas_visita` — formulário dinâmico
  - `funcionarios` (a equipa), `pedidos_funcionario` (colegas propostos), `envios_credenciais`
- Formulário dinâmico: os campos com `campo_sistema` gravam na coluna da visita (ex.: `motivo` → `visitas.motivo_id`);
  os outros gravam em `respostas_visita`. Ao remover um campo usa-se soft delete; para voltar a criar um campo com a
  mesma `chave` ou o mesmo `campo_sistema`, restaura-se o antigo (há chave única por formulário).
- Senha inicial: a mesma para todos (`00000000`), sem prazo, por decisão do projecto. A protecção é o bloqueio do ambiente
  (middleware `senha.alterada`) até a senha ser mudada.
- Email das credenciais: só um, quando a conta é criada. Ao redefinir a senha não se envia email (a senha volta a ser a
  inicial, que o administrador já conhece).
