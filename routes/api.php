<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Utilizador\RegistroController;
use App\Http\Controllers\Utilizador\LoginController;
use App\Http\Controllers\Utilizador\SenhaController;
use App\Http\Controllers\Utilizador\DocumentoController;
use App\Http\Controllers\Utilizador\HistoricoController;
use App\Http\Controllers\Utilizador\AvaliacaoController;
use App\Http\Controllers\Utilizador\PerfilController;
use App\Http\Controllers\SuporteController;
use App\Http\Controllers\Secretaria\CidadaoController;
use App\Http\Controllers\Secretaria\VisitaController;
use App\Http\Controllers\Secretaria\VisitanteController;
use App\Http\Controllers\Secretaria\ColegaController;
use App\Http\Controllers\Secretaria\RelatorioController;
use App\Http\Controllers\SuperAdmin\AdministradorController;
use App\Http\Controllers\SuperAdmin\AvaliacaoController as SuperAvaliacaoController;
use App\Http\Controllers\SuperAdmin\ColaboradorController;
use App\Http\Controllers\SuperAdmin\InstituicaoController as SuperInstituicaoController;
use App\Http\Controllers\SuperAdmin\PainelController as SuperPainelController;
use App\Http\Controllers\SuperAdmin\SuporteController as SuperSuporteController;
use App\Http\Controllers\Admin\CampoController;
use App\Http\Controllers\Admin\EquipaController;
use App\Http\Controllers\Admin\InstituicaoController as AdminInstituicaoController;
use App\Http\Controllers\Admin\PainelController as AdminPainelController;
use App\Http\Controllers\Admin\PedidoController;
use App\Http\Controllers\Admin\SectorController;

// =============================================================================================
// API do MeuAcesso — todas as rotas começam por /api (ex.: POST /api/login)
//
// Tipos de conta (users.tipo) e quem usa cada grupo de rotas:
//   0 utilizador (app móvel) · 1 funcionário (recepção) · 2 administrador · 3 super administrador
//
// Middlewares usados aqui (definidos em bootstrap/app.php):
//   auth:sanctum   → precisa do token: cabeçalho "Authorization: Bearer <token>"
//   tipo:N         → só deixa passar contas do tipo N (responde 403 SEM_PERMISSAO)
//   senha.alterada → fecha o ambiente enquanto a senha for a inicial 00000000 (403 SENHA_INICIAL)
//
// Erros: { mensagem } ou { errors: { campo: [mensagem] } } (validação do Laravel).
// =============================================================================================


// ---------------------------------------------------------------------------------------------
// PÚBLICAS (sem token)
// ---------------------------------------------------------------------------------------------

// Registo de um utilizador da app (tipo 0)  { nome, email, telefone, password, password_confirmation }
Route::post('/registro', [RegistroController::class, 'register']);

// Login de qualquer tipo de conta  { login: email ou telefone, password, canal?: app | portal }
// Com canal = "app" (app móvel) só entram cidadãos (tipo 0); as outras contas recebem 403 SO_PORTAL.
// → { utilizador: { token, tipo, deve_alterar_senha, ... } }  (o "tipo" decide a área no portal)
Route::post('/login', [LoginController::class, 'login']);

// Ecrã "Suporte" da app: contactos da empresa e perguntas frequentes (o super administrador muda-os)
//   → { contactos: { empresa, telefone, whatsapp, email, morada, horario, site }, perguntas: [ { id, categoria, pergunta, resposta } ] }
Route::get('/suporte', [SuporteController::class, 'index']);


// ---------------------------------------------------------------------------------------------
// COM TOKEN (Authorization: Bearer ...)
// ---------------------------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {

    // Termina a sessão: apaga só o token deste pedido
    Route::post('/logout', [LoginController::class, 'logout']);

    // Quem tem a sessão aberta → { utilizador: { tipo, area, deve_alterar_senha, ... } }
    // O portal chama-a depois de recarregar a página para saber a área da conta
    Route::get('/me', [LoginController::class, 'me']);

    // Mudar a senha (qualquer conta)  { password, password_confirmation, senha_actual }
    // Com a senha inicial (deve_alterar_senha = true) não pede senha_actual. É a única rota que
    // funciona enquanto a senha for a inicial (não passa pelo middleware senha.alterada).
    // Regras: mín. 8 caracteres, maiúscula, minúscula, número e diferente da actual.
    Route::put('/senha', [SenhaController::class, 'alterar']);

    // ----- Utilizador da app (tipo 0) ------------------------------------------------------
    // Só cidadãos: funcionários e administradores recebem 403 SEM_PERMISSAO (eles usam o portal web).
    // O login da app também recusa outras contas (POST /login com canal = "app").
    Route::middleware('tipo:0')->group(function () {
        // O cidadão envia e vê os seus documentos (BI frente/verso e fotografia)
        Route::get('/documentos', [DocumentoController::class, 'index']);
        Route::post('/documentos', [DocumentoController::class, 'store']);
        Route::post('/documentos/{tipo}', [DocumentoController::class, 'substituir']) // trocar uma só imagem
            ->whereIn('tipo', ['frente', 'verso', 'foto']);

        // O cidadão vê as suas visitas
        Route::get('/visitas', [HistoricoController::class, 'index']);
        Route::get('/visitas/instituicoes', [HistoricoController::class, 'instituicoes']); // os locais por onde passou
        Route::get('/visitas/{uuid}', [HistoricoController::class, 'show'])->whereUuid('uuid'); // uma visita em detalhe

        // Ecrã "Minha conta": o cidadão edita os seus dados  { nome, email, telefone }
        //   → { mensagem, utilizador: { uuid, numero, nome, email, telefone, tipo } }  (a senha muda-se em PUT /senha)
        Route::put('/perfil', [PerfilController::class, 'update']);

        // O cidadão avalia a app (vai para o super administrador) e vê a resposta da equipa
        //   POST { estrelas: 1-5, comentario?, plataforma?, app_versao? } → 201 { mensagem, id }
        //   GET /respostas → { respostas: [ { id, estrelas, comentario, resposta, respondido_em, vista_em, expira_em } ] }
        //                    (só as por ver e as vistas há menos de 1 h)
        //   POST /{id}/vista → marca a resposta como vista; desaparece da app 1 h depois
        Route::post('/avaliacoes', [AvaliacaoController::class, 'store']);
        Route::get('/avaliacoes/respostas', [AvaliacaoController::class, 'respostas']);
        Route::post('/avaliacoes/{id}/vista', [AvaliacaoController::class, 'vista'])->whereNumber('id');
    });

    // ----- Secretaria / recepção (tipo 1; o controller também aceita o tipo 2) --------------
    Route::prefix('secretaria')->group(function () {
        // Quem sou + sectores e motivos. Funciona com a senha inicial: o portal precisa dela para abrir
        // (e mostrar a janela obrigatória de mudança de senha).
        Route::get('/me', [VisitaController::class, 'me']);

        // Tudo o resto só depois de mudar a senha inicial (403 SENHA_INICIAL)
        Route::middleware('senha.alterada')->group(function () {
            Route::put('/perfil', [VisitaController::class, 'atualizarPerfil']);   // o funcionário edita o seu perfil
            Route::get('/cidadaos/{numero}', [CidadaoController::class, 'show']);  // procura pelo código MeuAcesso (ex.: 2A7341)
            Route::post('/visitas', [VisitaController::class, 'entrada']);         // registar entrada (código/QR)
            Route::post('/visitas/manual', [VisitaController::class, 'entradaManual']); // sem conta na app (BI físico)
            Route::post('/saida', [VisitaController::class, 'saida']);             // registar saída
            Route::get('/visitas', [VisitaController::class, 'index']);            // histórico com filtros
            Route::get('/visitantes', [VisitanteController::class, 'index']);      // pessoas que passaram pela instituição
            Route::get('/visitantes/{uuid}', [VisitanteController::class, 'show'])->whereUuid('uuid'); // ficha de uma pessoa
            Route::get('/dentro-agora', [VisitaController::class, 'dentroAgora']); // quem está dentro neste momento
            Route::get('/resumo', [VisitaController::class, 'resumo']);            // números do dia

            // Relatórios da recepção ?periodo=hoje|semana|mes|ano
            //   GET           → { periodo, kpis, serie, sectores, motivos, movimento, adopcao }
            //   GET /exportar → ficheiro do período escolhido: &formato=excel (CSV) | pdf (precisa do dompdf)
            Route::get('/relatorios', [RelatorioController::class, 'index']);
            Route::get('/relatorios/exportar', [RelatorioController::class, 'exportar']);

            // O funcionário propõe colegas (o administrador aprova em /admin/pedidos) e só vê os que ele propôs
            //   GET  → { data: [ { nome, email, cargo, estado: pendente|aprovado|recusado, motivo, em } ] }
            //   POST { nome, email, bi?, cargo?, justificacao? } → 201 { id, estado: 'pendente' }
            Route::get('/colegas', [ColegaController::class, 'index'])->middleware('tipo:1');
            Route::post('/colegas', [ColegaController::class, 'store'])->middleware('tipo:1');

            // Colegas activos da mesma instituição (só leitura)
            //   GET → { data: [ { id, nome, email, perfil, ultimo_acesso, eu } ] }
            Route::get('/equipa', [ColegaController::class, 'equipa'])->middleware('tipo:1');
        });
    });

    // ------------------------------------------------------------------------------------------
    // FLUXO  Super administrador → Administrador → (equipa)
    //   1. O Super administrador cria o acesso do administrador: só o email. Senha inicial de 4 algarismos, gerada ao acaso
    //      e um único email com as credenciais.
    //   2. O administrador entra. Enquanto a senha for a inicial, TODAS as rotas do ambiente
    //      respondem 403 SENHA_INICIAL (middleware senha.alterada); só PUT /senha funciona.
    //   3. Depois de mudar a senha, o ambiente está vazio: ele regista a PRÓPRIA instituição
    //      (PUT /admin/instituicao), os sectores, o formulário de visita e a equipa.
    //   4. Os funcionários podem propor colegas (/secretaria/colegas) e o administrador decide
    //      (/admin/pedidos). O Super administrador só consulta as instituições, não as regista.
    // Ver docs/fluxo-superadmin-admin.md
    // ------------------------------------------------------------------------------------------

    // ----- Super administrador (tipo 3) -----------------------------------------------------
    // senha.alterada: o colaborador nasce com a senha inicial e tem de a mudar antes de usar a área
    Route::prefix('superadmin')->middleware(['tipo:3', 'senha.alterada'])->group(function () {
        // Resumo da plataforma → { instituicoes, administradores, senhas_por_alterar, por_registar, funcionarios,
        //   visitas_mes, variacao_mes, visitas_por_mes: { meses, valores }, por_tipo, ultimas, utilizacao: { mobile, web } }
        Route::get('/painel', [SuperPainelController::class, 'index']);
        // App móvel (cidadãos) e portal web (administradores + funcionários)
        //   → { mobile, web, por_mes: [ { mes, mobile, web } ], por_instituicao: [ { sigla, nome, logo, mobile, web } ] }
        Route::get('/utilizacao', [SuperPainelController::class, 'utilizacao']);
        // Indicadores ?periodo=6m|ano|trimestre&tipo=Ministério&instituicao=MISAU
        //   → { kpis, por_mes, top, por_instituicao, tipos, siglas }   (?formato=pdf|xlsx ainda responde 501)
        Route::get('/relatorios', [SuperPainelController::class, 'relatorios']);
        // Instituições registadas pelos administradores (só consulta) ?q=texto&estado=activa|suspensa
        Route::get('/instituicoes', [SuperInstituicaoController::class, 'index']);

        // Avaliações da app (feedback dos cidadãos sobre a plataforma)
        //   GET ?estado=por_responder|respondidas&estrelas=1-5&q=texto
        //       → { data: [ { id, nome, email, codigo, estrelas, comentario, criado_em, resposta, respondido_em, respondido_por, vista_em } ],
        //           paginacao, resumo: { total, media, por_estrela, por_responder } }
        //   POST /{id}/responder { resposta } → uma só resposta (409 se já foi respondida)
        Route::get('/avaliacoes', [SuperAvaliacaoController::class, 'index']);
        Route::post('/avaliacoes/{id}/responder', [SuperAvaliacaoController::class, 'responder'])->whereNumber('id');

        // Suporte da app: contactos da empresa, perguntas frequentes e termos. Tudo devolve { contactos, perguntas, termos }
        //   PUT  /contactos { empresa, telefone, whatsapp?, email?, morada?, horario?, site? }
        //   POST /termos (multipart "ficheiro": PDF até 10 MB) → substitui os termos e condições anteriores
        //   POST /perguntas { pergunta, resposta, categoria?, activo? } · PUT /perguntas/{id} (os mesmos, opcionais)
        //   POST /perguntas/{id}/mover { direccao: cima|baixo } · DELETE /perguntas/{id}
        Route::get('/suporte', [SuperSuporteController::class, 'index']);
        Route::put('/suporte/contactos', [SuperSuporteController::class, 'contactos']);
        Route::post('/suporte/termos', [SuperSuporteController::class, 'termos']);
        Route::post('/suporte/perguntas', [SuperSuporteController::class, 'criar']);
        Route::put('/suporte/perguntas/{id}', [SuperSuporteController::class, 'actualizar'])->whereNumber('id');
        Route::post('/suporte/perguntas/{id}/mover', [SuperSuporteController::class, 'mover'])->whereNumber('id');
        Route::delete('/suporte/perguntas/{id}', [SuperSuporteController::class, 'remover'])->whereNumber('id');

        // Administradores (ecrãs "Credenciais" e "Registar administrador")
        //   GET  ?q=texto&senha=inicial|alterada → { data: [...], resumo: { total, senha_inicial, por_registar } }
        //   POST { email } → 201 { mensagem, email_enviado, administrador }  (envia o email das credenciais)
        //   POST .../redefinir-senha → gera uma senha inicial nova (devolvida em senha_gerada) e é obrigatório mudá-la (não envia email)
        // {uuid} = o "id" que a lista devolve
        Route::get('/administradores', [AdministradorController::class, 'index']);
        Route::post('/administradores', [AdministradorController::class, 'store']);
        Route::post('/administradores/{uuid}/redefinir-senha', [AdministradorController::class, 'redefinirSenha'])->whereUuid('uuid');

        // Colaboradores (co-adjuntos): só o super administrador principal (403 SO_PRINCIPAL para os colaboradores)
        //   GET  → { data: [ { id, nome, email, contacto, senha: inicial|alterada, estado, ultimo_acesso, criado_em } ] }
        //   POST { nome, email, contacto } → 201 { mensagem, email_enviado, colaborador }  (senha inicial gerada; senha_gerada só com MOSTRAR_SENHA_GERADA=true)
        Route::middleware('super.principal')->group(function () {
            Route::get('/colaboradores', [ColaboradorController::class, 'index']);
            Route::post('/colaboradores', [ColaboradorController::class, 'store']);
        });
    });

    // ----- Administrador da instituição (tipo 2) --------------------------------------------
    // A senha inicial muda-se em PUT /senha (acima), igual para todas as contas
    Route::prefix('admin')->middleware('tipo:2')->group(function () {
        // Tudo só depois de mudar a senha
        Route::middleware('senha.alterada')->group(function () {
            // Resumo e passos concluídos → { instituicao, visitas_hoje, dentro_agora, funcionarios, pedidos, passos, por_sector }
            Route::get('/painel', [AdminPainelController::class, 'index']);

            // A instituição deste administrador
            //   GET → { registada, nome, sigla, tipo, nuit, provincia, descricao, logo, endereco, telefone, email, abertura, fecho, dias }
            //   PUT → regista (1.ª vez) ou actualiza, com os mesmos campos. Nome, sigla, horário e dias são obrigatórios.
            //         "logo": data:image/...;base64 (nova imagem) · o URL actual (sem mudança) · "" (remover)
            //   POST /logo → alternativa em multipart (campo "logo": PNG, JPG, WebP ou SVG até 1 MB)
            Route::get('/instituicao', [AdminInstituicaoController::class, 'show']);
            Route::put('/instituicao', [AdminInstituicaoController::class, 'update']);
            Route::post('/instituicao/logo', [AdminInstituicaoController::class, 'logo']);

            // As rotas abaixo precisam da instituição registada (senão: 409 SEM_INSTITUICAO).
            // Todas as respostas de escrita devolvem a lista actualizada, para o ecrã não ter de a pedir de novo.

            // Sectores e motivos de visita → { sectores: [ { id, nome, piso, responsavel, activo } ], motivos: ['…', 'Outro'] }
            //   POST /sectores { nome, piso?, responsavel? } · PUT /sectores/{id} { nome?, piso?, responsavel?, activo? }
            //   DELETE /sectores/{id} · POST /motivos { nome } · DELETE /motivos/{nome}  ("Outro" é da plataforma: não sai)
            Route::get('/sectores', [SectorController::class, 'index']);
            Route::post('/sectores', [SectorController::class, 'store']);
            Route::put('/sectores/{id}', [SectorController::class, 'update'])->whereNumber('id');
            Route::delete('/sectores/{id}', [SectorController::class, 'destroy'])->whereNumber('id');
            Route::post('/motivos', [SectorController::class, 'motivo']);
            Route::delete('/motivos/{nome}', [SectorController::class, 'removerMotivo']);

            // Campos do formulário de visita (formulário dinâmico) → { campos: [...], tipos: ['Texto curto', ...] }
            //   POST { nome, tipo: 'Lista de escolha' | ..., obrigatorio?, opcoes?: ['A','B'] }
            //   PUT /{id} { nome?, obrigatorio?, visivel?, opcoes? } · DELETE /{id}
            //   "Motivo…"/"Sector…" (listas), BI, Telefone, "Pessoa a visitar" ligam-se às colunas da visita
            Route::get('/formulario/campos', [CampoController::class, 'index']);
            Route::post('/formulario/campos', [CampoController::class, 'store']);
            Route::put('/formulario/campos/{id}', [CampoController::class, 'update'])->whereNumber('id');
            Route::delete('/formulario/campos/{id}', [CampoController::class, 'destroy'])->whereNumber('id');

            // Equipa → { data: [ { id, nome, email, perfil, estado, senha_inicial, ultimo_acesso } ], funcoes }
            //   POST { nome, email, perfil: Secretária | Recepcionista | Funcionária } → cria a conta e envia as credenciais
            //   PUT /{uuid} { nome?, perfil?, estado?: activo | suspenso }  (suspender fecha as sessões abertas)
            Route::get('/equipa', [EquipaController::class, 'index']);
            Route::post('/equipa', [EquipaController::class, 'store']);
            Route::put('/equipa/{uuid}', [EquipaController::class, 'update'])->whereUuid('uuid');

            // Colegas propostos pelos funcionários (pendentes) → { data: [ { id, nome, email, bi, cargo, por, em } ] }
            //   aprovar: cria a conta (senha inicial + email) e junta-a à equipa · recusar { motivo? }
            Route::get('/pedidos', [PedidoController::class, 'index']);
            Route::post('/pedidos/{id}/aprovar', [PedidoController::class, 'aprovar'])->whereNumber('id');
            Route::post('/pedidos/{id}/recusar', [PedidoController::class, 'recusar'])->whereNumber('id');
        });
    });
});

// Link assinado (sem token, válido 30 min): é assim que as imagens aparecem em <img> e <Image>
Route::get('/ficheiros/{ficheiro:uuid}', [DocumentoController::class, 'ficheiro'])
    ->middleware('signed')
    ->name('ficheiros.ver');
