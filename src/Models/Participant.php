<?php

namespace Cmgmyr\Messenger\Models;

use App\Employee;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\SoftDeletes;

class Participant extends Eloquent
{
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'messenger_participants';

    /**
     * The attributes that can be set with Mass Assignment.
     *
     * @var array
     */
    protected $fillable = ['thread_id', 'user_id', 'last_read'];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['deleted_at', 'last_read'];

    /**
     * {@inheritDoc}
     */
    public function __construct(array $attributes = [])
    {
        $this->table = Models::table('messenger_participants');

        parent::__construct($attributes);
    }

    /**
     * Thread relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     *
     * @codeCoverageIgnore
     */
    public function thread()
    {
        return $this->belongsTo(Models::classname(Thread::class), 'thread_id', 'id');
    }

    /**
     * User relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     *
     * @codeCoverageIgnore
     */
    public function user()
    {
        return $this->belongsTo(Models::user(), 'user_id');
    }

    public function deletedMessages()
    {
        return $this->belongsToMany(Message::class, 'messenger_deleted_messages', 'message_id', 'user_id')->where('user_id', currentEmployee()->id);
    }

    public function deletedThreads()
    {
        return $this->belongsToMany(Thread::class, 'messenger_deleted_threads', 'thread_id', 'user_id')->where('user_id', currentEmployee()->id);
    }
}
