<?php

use App\Employee;

return [

    'user_model' => Employee::class,

    'message_model' => Cmgmyr\Messenger\Models\Message::class,

    'participant_model' => Cmgmyr\Messenger\Models\Participant::class,

    'thread_model' => Cmgmyr\Messenger\Models\Thread::class,

    /**
     * Define custom database table names - without prefixes.
     */
    'messages_table' => 'messenger_messages',

    'participantes_table' => 'messenger_participants',

    'threads_table' => 'messenger_threads',
];
