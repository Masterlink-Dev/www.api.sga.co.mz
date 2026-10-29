<?php

use App\Services\Suporte;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Primeiros dados do ecrã "Suporte" da app: os contactos da MasterLink e as perguntas frequentes.
// Depois o super administrador muda tudo no portal (Super administrador → Suporte).
// Não mexe no que já existir.
return new class extends Migration
{
    private const CONTACTOS = [
        'empresa'  => 'MasterLink',
        'telefone' => '+258 84 000 0000',
        'whatsapp' => '+258 84 000 0000',
        'email'    => 'suporte@meuacesso.co.mz',
        'morada'   => 'Maputo, Av. Eduardo Mondlane, esquina com a Av. Filipe Samuel Magaia',
        'horario'  => 'Segunda a sexta, das 08:00 às 17:00',
        'site'     => 'www.meuacesso.co.mz',
    ];

    private const PERGUNTAS = [
        ['Conta', 'O que é o código MeuAcesso?',
            'É um código de 6 caracteres só seu (por exemplo 2A7341). Na recepção de uma instituição, mostre o código ou o QR e a sua entrada fica registada em segundos, sem ter de preencher papéis nem entregar o BI.'],
        ['Conta', 'Onde encontro o meu código e o QR?',
            'No separador "Código", no fundo do ecrã. Também aparece no Início. Aumente o brilho do telemóvel para o QR ser lido mais depressa.'],
        ['Documentos', 'Porque tenho de enviar o BI?',
            'A instituição precisa de confirmar quem é. Ao enviar a frente e o verso do BI uma única vez, os seus dados ficam prontos e não precisa de mostrar o documento físico em cada visita.'],
        ['Documentos', 'Os meus dados estão seguros?',
            'Sim. Os documentos ficam guardados de forma protegida e só a recepção da instituição onde está a ser atendido vê os dados necessários para registar a visita.'],
        ['Visitas', 'Onde vejo as minhas visitas?',
            'No separador "Histórico". Cada visita aparece no dia em que aconteceu, com a instituição, a hora de entrada e de saída e quanto tempo demorou.'],
        ['Visitas', 'Uma visita minha não aparece no histórico. O que faço?',
            'Só aparecem as visitas registadas com o seu código MeuAcesso. Se a recepção registou a entrada com outro documento, a visita não fica ligada à sua conta. Em caso de dúvida, contacte-nos.'],
        ['Conta', 'Esqueci a palavra-passe. Como recupero o acesso?',
            'No ecrã de entrada, toque em "Esqueci a palavra-passe" e siga os passos. Se não conseguir, fale connosco pelos contactos abaixo.'],
        ['App', 'Como posso dar a minha opinião sobre a app?',
            'Aqui mesmo, no ecrã "Feedback e suporte": escolha as estrelas, escreva um comentário (opcional) e toque em "Enviar feedback". A equipa lê todas as avaliações e pode responder-lhe.'],
    ];

    public function up(): void
    {
        $existentes = DB::table('definicoes_sistema')->where('grupo', 'suporte')->pluck('chave')->all();
        Suporte::guardarContactos(array_filter(
            self::CONTACTOS,
            fn ($campo) => ! in_array("suporte.$campo", $existentes, true),
            ARRAY_FILTER_USE_KEY
        ));
        DB::table('definicoes_sistema')->where('grupo', 'suporte')->whereNull('created_at')->update(['created_at' => now()]);

        if (DB::table('perguntas_frequentes')->exists()) return;

        foreach (self::PERGUNTAS as $i => [$categoria, $pergunta, $resposta]) {
            DB::table('perguntas_frequentes')->insert([
                'publico'    => 'cidadao',
                'categoria'  => $categoria,
                'pergunta'   => $pergunta,
                'resposta'   => $resposta,
                'ordem'      => $i + 1,
                'activo'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('definicoes_sistema')->where('grupo', 'suporte')->delete();
        DB::table('perguntas_frequentes')->whereIn('pergunta', array_column(self::PERGUNTAS, 1))->delete();
    }
};
