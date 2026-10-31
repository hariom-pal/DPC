<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| A department's year-wise vacancy records (Vacancy Management), loaded by two route
| groups in web.php: the department's area, where its Authorized Officer keeps and
| finalizes them (2026-10-19), and the Administrator's, where they are read-only.
| The controller decides who may write, not the route.
*/

Route::get('/', 'index')->name('index');
Route::get('/{year}/posts/{post}', 'show')->whereNumber('year')->name('show');
Route::post('/{year}/posts/{post}/open', 'open')->whereNumber('year')->name('open');
Route::post('/{year}/posts/{post}/sanctioned-strength', 'saveSanctionedStrength')->whereNumber('year')->name('sanctioned-strength');
Route::get('/{year}/posts/{post}/sanctioned-strength/{sanctionedStrength}/document', 'sanctionedStrengthDocument')->whereNumber('year')->name('sanctioned-strength.document');
Route::post('/{year}/posts/{post}/strength', 'saveStrength')->whereNumber('year')->name('strength');
Route::post('/{year}/posts/{post}/working-strength', 'saveWorkingStrength')->whereNumber('year')->name('working-strength');
Route::get('/{year}/posts/{post}/working-strength/{workingStrength}/document', 'workingStrengthDocument')->whereNumber('year')->name('working-strength.document');
Route::post('/{year}/posts/{post}/entries', 'storeEntry')->whereNumber('year')->name('entries.store');
Route::post('/{year}/posts/{post}/entries/{entry}/verify', 'verifyEntry')->whereNumber('year')->name('entries.verify');
Route::post('/{year}/posts/{post}/entries/{entry}/withdraw', 'withdrawEntry')->whereNumber('year')->name('entries.withdraw');
Route::get('/{year}/posts/{post}/entries/{entry}/document', 'entryDocument')->whereNumber('year')->name('entries.document');
Route::post('/{year}/posts/{post}/finalize', 'finalize')->whereNumber('year')->name('finalize');
Route::post('/{year}/posts/{post}/revise', 'revise')->whereNumber('year')->name('revise');
