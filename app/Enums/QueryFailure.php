<?php

namespace App\Enums;

/**
 * Why a query did not produce an answer.
 *
 * The monitor does not care — a failure is downtime whatever its shape. The
 * submission form does: it is talking to a person who is trying to get their
 * server listed, and "the port is shut" and "the port is open but nothing
 * answered" send them to opposite ends of their server configuration.
 */
enum QueryFailure: string
{
    /** The hostname has no address. */
    case Unresolvable = 'unresolvable';

    /** Nothing accepted us: refused, unroutable, or no such listener. */
    case Unreachable = 'unreachable';

    /**
     * We got in and heard nothing back.
     *
     * Over TCP this means a listener accepted the connection and then ignored
     * the request — status switched off, or a filter in front of it deciding we
     * are not a real client. Over UDP there is no connection to accept, so it
     * only means silence, and the wording has to stay vaguer.
     */
    case Silent = 'silent';

    /** Something answered, but not in a shape the protocol allows. */
    case Malformed = 'malformed';
}
