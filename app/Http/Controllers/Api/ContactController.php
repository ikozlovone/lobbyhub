<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * The site's contact form on the server side.
 *
 * One thing this does: validate what was sent and forward it to the support
 * inbox, with the visitor's own address set as the reply-to so replying from
 * the inbox reaches them without any address book work. No copy is stored in
 * the database — a contact message is not something we own past the point of
 * having read it, and keeping it would make it another thing subject to a
 * data-subject request.
 *
 * `services.mail.contact_to` is the target, so a fork does not silently
 * inherit the address in the deployment it was cloned from. Rate-limited by
 * the `contact` limiter (see AppServiceProvider).
 */
class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $to = (string) config('services.mail.contact_to', config('mail.from.address'));

        Mail::to($to)->send(new ContactMessage(
            fromEmail: $data['email'],
            fromName: $data['name'] ?? null,
            subject: $data['subject'],
            body: $data['message'],
            ip: (string) $request->ip(),
        ));

        return response()->json(['message' => 'Message sent. We will reply within one business day.']);
    }
}
