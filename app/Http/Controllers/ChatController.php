<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\AI\ChatAnswerService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private readonly ChatAnswerService $answers) {}

    public function index()
    {
        $conversation = $this->currentConversation();

        return view('chat.index', [
            'messages' => $conversation->messages,
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        $conversation = $this->currentConversation();

        $userMessage = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $data['question'],
        ]);

        try {
            $result = $this->answers->answer(auth()->id(), $data['question']);
        } catch (\Throwable $e) {
            $result = [
                'type' => 'unanswerable',
                'content' => "Something went wrong answering that: {$e->getMessage()}",
                'computed_spec' => null,
                'citation_data' => null,
            ];
        }

        $assistantMessage = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'answer_type' => $result['type'],
            'content' => $result['content'],
            'computed_spec' => $result['computed_spec'],
            'citation_data' => $result['citation_data'],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'user_html' => view('partials.chat-message', ['message' => $userMessage])->render(),
                'assistant_html' => view('partials.chat-message', ['message' => $assistantMessage])->render(),
            ]);
        }

        return redirect()->route('chat.index');
    }

    private function currentConversation(): ChatConversation
    {
        return ChatConversation::firstOrCreate(['user_id' => auth()->id()]);
    }
}
