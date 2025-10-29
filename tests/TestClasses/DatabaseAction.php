<?php

namespace Iak\Action\Tests\TestClasses;

use Iak\Action\Action;
use Illuminate\Support\Facades\DB;

class DatabaseAction extends Action
{
    public function handle()
    {
        // Create temporary tables for testing
        DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS posts (id INTEGER PRIMARY KEY, user_id INTEGER)');
        DB::statement('INSERT OR IGNORE INTO users (id) VALUES (1)');
        
        // Execute actual queries that will be recorded
        DB::select('SELECT * FROM users WHERE id = ?', [1]);
        DB::select('SELECT * FROM posts WHERE user_id = ?', [1]);
        
        return 'Database queries executed';
    }
}

