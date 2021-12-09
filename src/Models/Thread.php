<?php

namespace Cmgmyr\Messenger\Models;

use App\Employee;
use App\MessengerGroups;
use App\MessengerFirstMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Thread extends Eloquent
{
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'messenger_threads';

    /**
     * The attributes that can be set with Mass Assignment.
     *
     * @var array
     */
    protected $fillable = ['subject', 'company_id', 'moderator'];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['deleted_at'];

    protected $appends = ['last_message_text', 'last_message_timestamp', 'last_message_at', 'unread_messages', 'participants_string', 'participants_id', 'qtd_messages', 'is_group', 'is_emoji'];

    /**
     * Internal cache for creator.
     *
     * @var null|Models::user()|\Illuminate\Database\Eloquent\Model
     */
    protected $creatorCache;

    /**
     * {@inheritDoc}
     */
    public function __construct(array $attributes = [])
    {
        $this->table = Models::table('messenger_threads');

        parent::__construct($attributes);
    }

    /**
     * Messages relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     *
     * @codeCoverageIgnore
     */
    public function messages()
    {
        return $this->hasMany(Models::classname(Message::class), 'thread_id', 'id')->where(function ($query) {
            $query->where('to_user_id', currentEmployee()->id)
                ->orWhere('system_message', 0)
                ->orWhere('system_message', 1);
        });
    }

    public function messagesNotification()
    {
        return $this->hasMany(Models::classname(Message::class), 'thread_id', 'id');
    }

    public function messagesSystemNotification()
    {
        return $this->hasMany(Models::classname(Message::class), 'thread_id', 'id')->where(function ($query) {
            $query->where('system_message', 1);
        });
    }

    /**
     * Returns the latest message from a thread.
     *
     * @return null|\Cmgmyr\Messenger\Models\Message
     */
    public function getLatestMessageAttribute()
    {
        return $this->messages()->latest()->first();
    }

    /**
     * Participants relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     *
     * @codeCoverageIgnore
     */
    public function participants()
    {
        return $this->hasMany(Models::classname(Participant::class), 'thread_id', 'id');
    }

    /**
     * User's relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     *
     * @codeCoverageIgnore
     */
    public function users()
    {
        return $this->belongsToMany(Models::classname('Employee'), Models::table('messenger_participants'), 'thread_id', 'user_id');
    }

    /**
     * Returns the user object that created the thread.
     *
     * @return null|Models::user()|\Illuminate\Database\Eloquent\Model
     */
    public function creator()
    {
        if ($this->creatorCache === null) {
            $firstMessage = $this->messages()->withTrashed()->oldest()->first();
            $this->creatorCache = $firstMessage ? $firstMessage->user : Models::user();
        }

        return $this->creatorCache;
    }

    /**
     * Returns all of the latest threads by updated_at date.
     *
     * @return \Illuminate\Database\Query\Builder|static
     */
    public static function getAllLatest()
    {
        return static::latest('updated_at');
    }

    /**
     * Returns all threads by subject.
     *
     * @param string $subject
     *
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public static function getBySubject($subject)
    {
        return static::where('subject', 'like', $subject)->get();
    }

    /**
     * Returns an array of user ids that are associated with the thread.
     *
     * @param null|int $userId
     *
     * @return array
     */
    public function participantsUserIds($userId = null)
    {
        $users = $this->participants()->withTrashed()->select('user_id')->get()->map(function ($participant) {
            return $participant->user_id;
        });

        if ($userId !== null) {
            $users->push($userId);
        }

        return $users->toArray();
    }

    /**
     * Returns threads that the user is associated with.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser(Builder $query, $userId)
    {
        $participantsTable = Models::table('messenger_participants');
        $threadsTable = Models::table('messenger_threads');

        return $query->join($participantsTable, $this->getQualifiedKeyName(), '=', $participantsTable . '.thread_id')
            ->where($participantsTable . '.user_id', $userId)
            ->whereNull($participantsTable . '.deleted_at')
            ->select($threadsTable . '.*');
    }

    /**
     * Returns threads with new messages that the user is associated with.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUserWithNewMessages(Builder $query, $userId)
    {
        $participantTable = Models::table('messenger_participants');
        $threadsTable = Models::table('messenger_threads');

        return $query->join($participantTable, $this->getQualifiedKeyName(), '=', $participantTable . '.thread_id')
            ->where($participantTable . '.user_id', $userId)
            ->whereNull($participantTable . '.deleted_at')
            ->where(function (Builder $query) use ($participantTable, $threadsTable) {
                $query->where($threadsTable . '.updated_at', '>', $this->getConnection()->raw($this->getConnection()->getTablePrefix() . $participantTable . '.last_read'))
                    ->orWhereNull($participantTable . '.last_read');
            })
            ->select($threadsTable . '.*');
    }

    /**
     * Returns threads between given user ids.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $participants
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBetween(Builder $query, array $participants)
    {
        return $query->whereHas('messenger_participants', function (Builder $q) use ($participants) {
            $q->whereIn('user_id', $participants)
                ->select($this->getConnection()->raw('DISTINCT(thread_id)'))
                ->groupBy('thread_id')
                ->havingRaw('COUNT(thread_id)=' . count($participants));
        });
    }

    /**
     * Add users to thread as participants.
     *
     * @param array|mixed $userId
     *
     * @return void
     */
    public function addParticipant($userId)
    {
        $userIds = is_array($userId) ? $userId : (array) func_get_args();

        collect($userIds)->each(function ($userId) {
            Models::participant()->firstOrCreate([
                'user_id' => $userId,
                'thread_id' => $this->id,
            ]);
        });
    }

    public function addModerator($userId)
    {
        $userIds = is_array($userId) ? $userId : (array) func_get_args();

        collect($userIds)->each(function ($userId) {
            $participant = Models::participant()->where('user_id', $userId)->where('thread_id', $this->id)->first();

            if ($participant) {
                $participant->moderator = 1;
                $participant->save();
            }
        });
    }

    /**
     * Remove participants from thread.
     *
     * @param array|mixed $userId
     *
     * @return void
     */
    public function removeParticipant($userId)
    {
        $userIds = is_array($userId) ? $userId : (array) func_get_args();

        Models::participant()->where('thread_id', $this->id)->whereIn('user_id', $userIds)->delete();
    }

    public function removeModerator($userId)
    {
        $userIds = is_array($userId) ? $userId : (array) func_get_args();

        collect($userIds)->each(function ($userId) {
            $participant = Models::participant()->where('user_id', $userId)->where('thread_id', $this->id)->first();

            if ($participant) {
                $participant->moderator = 0;
                $participant->save();
            }
        });
    }

    /**
     * Mark a thread as read for a user.
     *
     * @param int $userId
     *
     * @return void
     */
    public function markAsRead($userId)
    {
        try {
            $participant = $this->getParticipantFromUser($userId);
            $participant->last_read = new Carbon();
            $participant->save();
        } catch (ModelNotFoundException $e) { // @codeCoverageIgnore
            // do nothing
        }
    }

    /**
     * See if the current thread is unread by the user.
     *
     * @param int $userId
     *
     * @return bool
     */
    public function isUnread($userId)
    {
        try {
            $participant = $this->getParticipantFromUser($userId);

            if ($participant->last_read === null || $this->updated_at->gt($participant->last_read)) {
                return true;
            }
        } catch (ModelNotFoundException $e) { // @codeCoverageIgnore
            // do nothing
        }

        return false;
    }

    /**
     * Finds the participant record from a user id.
     *
     * @param $userId
     *
     * @return mixed
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getParticipantFromUser($userId)
    {
        return $this->participants()->where('user_id', $userId)->firstOrFail();
    }

    /**
     * Restores only trashed participants within a thread that has a new message.
     * Others are already active participiants.
     *
     * @return void
     */
    public function activateAllParticipants()
    {
        $participants = $this->participants()->onlyTrashed()->get();
        foreach ($participants as $participant) {
            $participant->restore();
        }
    }

    /**
     * Generates a string of participant information.
     *
     * @param null|int $userId
     * @param array $columns
     *
     * @return string
     */
    public function participantsString($userId = null, $columns = ['name'])
    {
        $participantsTable = Models::table('messenger_participants');
        $usersTable = Models::table('users');
        $userPrimaryKey = Models::user()->getKeyName();

        $selectString = $this->createSelectString($columns);

        $participantNames = $this->getConnection()->table($usersTable)
            ->join($participantsTable, $usersTable . '.' . $userPrimaryKey, '=', $participantsTable . '.user_id')
            ->where($participantsTable . '.thread_id', $this->id)
            ->select($this->getConnection()->raw($selectString));

        if ($userId !== null) {
            $participantNames->where($usersTable . '.' . $userPrimaryKey, '!=', $userId);
        }

        return $participantNames->implode('name', ', ');
    }

    public function participantsId($userId = null, $columns = ['id'])
    {
        $participantsTable = Models::table('messenger_participants');
        $usersTable = Models::table('users');
        $userPrimaryKey = Models::user()->getKeyName();

        $selectString = $this->createSelectString($columns);

        $participantNames = $this->getConnection()->table($usersTable)
            ->join($participantsTable, $usersTable . '.' . $userPrimaryKey, '=', $participantsTable . '.user_id')
            ->where($participantsTable . '.thread_id', $this->id)
            ->select($this->getConnection()->raw($selectString));

        if ($userId !== null) {
            $participantNames->where($usersTable . '.' . $userPrimaryKey, '!=', $userId);
        }

        return $participantNames->implode('name', ', ');
    }

    /**
     * Checks to see if a user is a current participant of the thread.
     *
     * @param int $userId
     *
     * @return bool
     */
    public function hasParticipant($userId)
    {
        $participants = $this->participants()->where('user_id', '=', $userId);

        return $participants->count() > 0;
    }

    /**
     * Generates a select string used in participantsString().
     *
     * @param array $columns
     *
     * @return string
     */
    protected function createSelectString($columns)
    {
        $dbDriver = $this->getConnection()->getDriverName();
        $tablePrefix = $this->getConnection()->getTablePrefix();
        $usersTable = Models::table('users');

        switch ($dbDriver) {
            case 'pgsql':
            case 'sqlite':
                $columnString = implode(" || ' ' || " . $tablePrefix . $usersTable . '.', $columns);
                $selectString = '(' . $tablePrefix . $usersTable . '.' . $columnString . ') as name';
                break;
            case 'sqlsrv':
                $columnString = implode(" + ' ' + " . $tablePrefix . $usersTable . '.', $columns);
                $selectString = '(' . $tablePrefix . $usersTable . '.' . $columnString . ') as name';
                break;
            default:
                $columnString = implode(", ' ', " . $tablePrefix . $usersTable . '.', $columns);
                $selectString = 'concat(' . $tablePrefix . $usersTable . '.' . $columnString . ') as name';
        }

        return $selectString;
    }

    /**
     * Returns array of unread messages in thread for given user.
     *
     * @param int $userId
     *
     * @return \Illuminate\Support\Collection
     */
    public function userUnreadMessages($userId)
    {
        $messages = $this->messages()->where('user_id', '!=', $userId)->get();

        try {
            $participant = $this->getParticipantFromUser($userId);
        } catch (ModelNotFoundException $e) {
            return collect();
        }

        if (!$participant->last_read) {
            return $messages;
        }

        return $messages->filter(function ($message) use ($participant) {
            return $message->updated_at->gt($participant->last_read);
        });
    }


    /**
     * Returns count of unread messages in thread for given user.
     *
     * @param int $userId
     *
     * @return int
     */
    public function userUnreadMessagesCount($userId)
    {
        return $this->userUnreadMessages($userId)->count();
    }

    public function threadsTheUserDeleted()
    {
        return $this->belongsToMany(Employee::class, 'messenger_deleted_threads', 'thread_id', 'user_id')->where('user_id', currentEmployee()->id);
    }

    public function group()
    {
        return $this->hasOne(MessengerGroups::class);
    }

    public function undeletedMessages()
    {
        return $this->hasMany(Models::classname(Message::class), 'thread_id', 'id')->doesntHave('messagesTheUserDeleted');
        // return $this->hasMany(Models::classname(Message::class), 'thread_id', 'id')->doesntHave('messagesTheUserDeleted')->where(function ($query) {
        //     $query->where('to_user_id', currentEmployee()->id)
        //         ->orWhere('system_message', 0);
        // });
    }

    public function getIsEmojiAttribute()
    {
        $last = $this->undeletedMessages->last();

        if ($last) {
            $emojiRegex = '/([0-9#][\x{20E3}])|[\x{00ae}\x{00a9}\x{203C}\x{2047}\x{2048}\x{2049}\x{3030}\x{303D}\x{2139}\x{2122}\x{3297}\x{3299}][\x{FE00}-\x{FEFF}]?|[\x{2190}-\x{21FF}][\x{FE00}-\x{FEFF}]?|[\x{2300}-\x{23FF}][\x{FE00}-\x{FEFF}]?|[\x{2460}-\x{24FF}][\x{FE00}-\x{FEFF}]?|[\x{25A0}-\x{25FF}][\x{FE00}-\x{FEFF}]?|[\x{2600}-\x{27BF}][\x{FE00}-\x{FEFF}]?|[\x{2900}-\x{297F}][\x{FE00}-\x{FEFF}]?|[\x{2B00}-\x{2BF0}][\x{FE00}-\x{FEFF}]?|[\x{1F000}-\x{1F6FF}][\x{FE00}-\x{FEFF}]?/u';
            $text = preg_replace($emojiRegex, '', $last->body);

            preg_match_all('/([0-9#][\x{20E3}])|[\x{00ae}\x{00a9}\x{203C}\x{2047}\x{2048}\x{2049}\x{3030}\x{303D}\x{2139}\x{2122}\x{3297}\x{3299}][\x{FE00}-\x{FEFF}]?|[\x{2190}-\x{21FF}][\x{FE00}-\x{FEFF}]?|[\x{2300}-\x{23FF}][\x{FE00}-\x{FEFF}]?|[\x{2460}-\x{24FF}][\x{FE00}-\x{FEFF}]?|[\x{25A0}-\x{25FF}][\x{FE00}-\x{FEFF}]?|[\x{2600}-\x{27BF}][\x{FE00}-\x{FEFF}]?|[\x{2900}-\x{297F}][\x{FE00}-\x{FEFF}]?|[\x{2B00}-\x{2BF0}][\x{FE00}-\x{FEFF}]?|[\x{1F000}-\x{1F6FF}][\x{FE00}-\x{FEFF}]?/u', $last->body, $emojis);
            $isEmoji = !empty($emojis[0]) ? true : false;

            if ($text == '' && $isEmoji)
                return true;
        }

        return false;
    }

    public function getLastMessageTextAttribute()
    {
        $last = $this->undeletedMessages->last();

        if ($last) {
            if ($last->body == '') {
                if ($last->attachment) {
                    return '<span style="font-style: italic;">Imagem</span>';
                } else {
                    return '<span style="font-style: italic;">' . strip_tags(str_replace('&nbsp;', ' ', $last->file_name)) . '</span>';
                }
            } else {
                if ($last->body == 'Esta mensagem foi excluída')
                    return '<span style="font-style: italic;">' . strip_tags(str_replace('&nbsp;', ' ', $last->body)) . '</span>';
                else
                    return '<span>' . strip_tags(str_replace('&nbsp;', ' ', $last->body)) . '</span>';
            }
        } else {
            return '<span style="visibility: hidden">NULL</span>';
        }
    }

    public function getLastMessageTimestampAttribute()
    {
        $last = $this->undeletedMessages->last();

        if ($last) {
            return $last->created_at;
        } else {
            return $this->created_at;
        }
    }

    public function getLastMessageAtAttribute()
    {
        $last = $this->undeletedMessages->last();

        if ($last) {
            $date1 = $last->created_at;
            $date2 = \Carbon\Carbon::parse(now()->format('Y-m-d') . '00:00:00');

            if ($date1 < $date2) {
                return $last->created_at->format('d/m/Y H:i');
            } else
                return $last->created_at->format('H:i');
        } else {
            return '';
        }
    }

    public function getUnreadMessagesAttribute()
    {
        return $this->userUnreadMessagesCount(currentEmployee()->id);
    }

    public function getParticipantsStringAttribute()
    {
        return $this->participantsString(currentEmployee()->id);
    }

    public function getParticipantsIdAttribute()
    {
        return $this->participantsId(currentEmployee()->id);
    }

    public function getIsGroupAttribute()
    {
        if ($this->group) {
            return true;
        } else {
            return false;
        }
    }

    public function getQtdMessagesAttribute()
    {
        $last = $this->undeletedMessages->last();

        if ($last) {
            return 1;
        } else {
            return 0;
        }
    }

    public function firstMessage()
    {
        return $this->hasOne(MessengerFirstMessage::class);
    }
}
