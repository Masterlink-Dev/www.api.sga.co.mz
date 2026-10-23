<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Cidadao extends Model
{
    use SoftDeletes;

    protected $table = 'cidadaos';

    protected $guarded = ['id', 'uuid'];

    protected $hidden = ['numero_documento', 'numero_documento_hash', 'nome_pai', 'nome_mae'];

    protected function casts(): array
    {
        return [
            'numero_documento' => 'encrypted',
            'nome_pai' => 'encrypted',
            'nome_mae' => 'encrypted',
            'data_nascimento' => 'date',
            'documento_data_emissao' => 'date',
            'documento_data_validade' => 'date',
            'verificado_em' => 'datetime',
            'foto_verificada' => 'boolean',
            'extra' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Cidadao $c) => $c->uuid ??= (string) Str::uuid());
    }

    // HMAC do nº do documento, para pesquisar sem desencriptar
    public static function hashDocumento(string $numero): string
    {
        return hash_hmac('sha256', strtoupper(preg_replace('/\s+/', '', $numero)), config('app.key'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function foto(): BelongsTo
    {
        return $this->belongsTo(Ficheiro::class, 'foto_ficheiro_id');
    }

    public function documentoFrente(): BelongsTo
    {
        return $this->belongsTo(Ficheiro::class, 'documento_frente_ficheiro_id');
    }

    public function documentoVerso(): BelongsTo
    {
        return $this->belongsTo(Ficheiro::class, 'documento_verso_ficheiro_id');
    }

    // Quando o BI da conta fica registado, liga as visitas antigas à mesma pessoa (pelo nº do BI):
    //  · visitas feitas ao balcão com este BI, quando ainda não havia conta → passam a ter o cidadão
    //  · visitas feitas pelo código antes de o BI estar registado → ficam com o nº do BI
    // Assim a pessoa aparece uma só vez na lista da instituição e vê todas as suas visitas na app.
    public function ligarVisitas(): void
    {
        $bi = $this->numero_documento; // desencriptado pelo modelo
        if (! $bi) {
            return;
        }

        $bi = strtoupper(preg_replace('/\s+/', '', $bi));

        DB::table('visitas')->whereNull('cidadao_id')->where('documento_numero', $bi)
            ->update(['cidadao_id' => $this->id]);

        DB::table('visitas')->where('cidadao_id', $this->id)
            ->where(fn ($q) => $q->whereNull('documento_numero')->orWhere('documento_numero', ''))
            ->update(['documento_numero' => $bi]);
    }

    // As imagens enviadas, no formato que a app e o portal recebem
    public function documentosParaApi(): array
    {
        $bi = in_array($this->nivel_verificacao, ['documento', 'presencial', 'oficial']);

        $itens = [
            'frente' => [$this->documentoFrente, $bi],
            'verso'  => [$this->documentoVerso, $bi],
            'foto'   => [$this->foto, (bool) $this->foto_verificada],
        ];

        $saida = [];
        foreach ($itens as $tipo => [$ficheiro, $verificado]) {
            if (! $ficheiro) {
                continue;
            }
            $saida[] = [
                'tipo'       => $tipo,
                'nome'       => $ficheiro->nome_original,
                'mime'       => $ficheiro->mime,
                'tamanho'    => $ficheiro->tamanho_bytes,
                'estado'     => $verificado ? 'verificado' : 'analise',
                'enviado_em' => $ficheiro->updated_at,
                'url'        => $ficheiro->url(),
            ];
        }

        return $saida;
    }
}