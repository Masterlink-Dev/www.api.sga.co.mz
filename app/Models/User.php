<?php

namespace App\Models;

use App\Support\CodigoMeuAcesso;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // Tipos de conta (coluna users.tipo)
    public const TIPO_UTILIZADOR = 0;  // cidadão que usa a app
    public const TIPO_FUNCIONARIO = 1; // secretaria / recepção
    public const TIPO_ADMIN = 2;       // administrador de uma instituição
    public const TIPO_SUPER_ADMIN = 3; // equipa que mantém a plataforma MeuAcesso

    // A senha inicial é gerada ao acaso para cada conta nova, com este número de algarismos (AppServicesCredenciais)
    public const DIGITOS_SENHA_INICIAL = 4;

    protected $fillable = [
        'uuid',
        'numero',    // código MeuAcesso: 5 algarismos + 1 letra (vai no QR code)
        'tipo',      // 0 utilizador, 1 funcionário, 2 admin, 3 super admin
        'colaborador', // só no tipo 3: true = colaborador do super administrador (não regista colaboradores)
        'name',
        'email',
        'telefone',
        'password',
        'estado',
        'criado_por',         // quem criou a conta (super administrador ou administrador)
        'deve_alterar_senha', // true enquanto a senha for a inicial
        'senha_alterada_em',
        'primeiro_acesso_em',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'colaborador' => 'boolean',
            'deve_alterar_senha' => 'boolean',
            'senha_alterada_em' => 'datetime',
            'primeiro_acesso_em' => 'datetime',
        ];
    }

    // Gera um código MeuAcesso (5 algarismos + 1 letra) que ainda ninguém tem
    public static function gerarNumero(): string
    {
        return CodigoMeuAcesso::gerar(fn (string $codigo) => static::where('numero', $codigo)->exists());
    }

    public function cidadao(): HasOne
    {
        return $this->hasOne(Cidadao::class);
    }

    // O registo do cidadão desta conta (cria-o se ainda não existir)
    public function cidadaoOuCriar(): Cidadao
    {
        return $this->cidadao ?? $this->cidadao()->create([
            'nome_completo' => $this->name,
            'telefone' => $this->telefone,
            'email' => $this->email,
            'origem' => 'app',
        ]);
    }
}