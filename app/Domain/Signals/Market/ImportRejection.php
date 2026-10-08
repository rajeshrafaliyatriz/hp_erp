<?php

namespace App\Domain\Signals\Market;

use Illuminate\Database\Eloquent\Model;

/** A record that failed the qualification gates. Kept, with its payload, so it can be fixed and re-sent. */
class ImportRejection extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_signal_import_rejections';

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array'];
}
