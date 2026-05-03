<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// V2: Abstract base for all models. Forces the pgsql connection so no model accidentally hits SQLite or another DB.
abstract class BaseModel extends Model
{
    protected $connection = 'pgsql';
}
