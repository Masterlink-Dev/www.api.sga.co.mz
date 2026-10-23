<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Ficheiro extends Model
{
    use SoftDeletes;

    protected $table = 'ficheiros';

    protected $guarded = ['id', 'uuid'];

    protected static function booted(): void
    {
        static::creating(fn (Ficheiro $f) => $f->uuid ??= (string) Str::uuid());
    }

    // Link temporário (30 min) para mostrar a imagem em <img> ou <Image>
    public function url(): string
    {
        return URL::temporarySignedRoute('ficheiros.ver', now()->addMinutes(30), ['ficheiro' => $this->uuid]);
    }
}