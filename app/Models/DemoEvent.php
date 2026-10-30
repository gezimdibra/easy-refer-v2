<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One demo instrumentation event (G6 demo Phase 1, Section
 * 15.1). The payload is closed by construction: the opaque
 * demo session key, the event name, a step name, an entry
 * point, the channel. No phone, no shop name, no free text
 * can ride an event because no column exists to carry them.
 */
class DemoEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'session_key',
        'event',
        'step',
        'at_step',
        'entry_point',
        'channel',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public static function record(string $sessionKey, string $event, ?string $step = null, ?string $entryPoint = null, ?string $atStep = null): self
    {
        return static::create([
            'session_key' => $sessionKey,
            'event' => $event,
            'step' => $step,
            'at_step' => $atStep,
            'entry_point' => $entryPoint,
            'channel' => 'embedded',
            'created_at' => now(),
        ]);
    }
}
