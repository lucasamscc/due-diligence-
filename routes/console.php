<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Ingestão de dataset: pipeline independente das diligências.
| withoutOverlapping: duas ingestões da mesma fonte ao mesmo tempo disputariam o swap.
| onOneServer: com N servidores rodando schedule:run, só um baixa (lock no Redis).
*/
Schedule::command('datasets:ingest-ibama')
    ->monthlyOn(5, '03:00')
    ->withoutOverlapping()
    ->onOneServer();

// Rede de segurança do fan-in: fecha diligências cujo contador Redis se perdeu.
Schedule::command('diligence:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
