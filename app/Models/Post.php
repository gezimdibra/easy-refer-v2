<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A blog post (spec Section 2.5). Launch ships the index only; posts
 * are seeded content, never authored through the app.
 */
class Post extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'title',
        'excerpt',
        'body',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
