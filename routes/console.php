<?php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
Artisan::command('pharma:about', function(){ $this->info('Pharma Strategies — private workplace communication for Canadian pharmacy teams.'); })->purpose('Show application information');
