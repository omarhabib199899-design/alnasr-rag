<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\DashboardWidget;
use Illuminate\Http\Request;

class WidgetController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'chat_message_id' => ['required', 'integer'],
        ]);

        $message = ChatMessage::with('conversation')->findOrFail($data['chat_message_id']);
        abort_if($message->conversation->user_id !== auth()->id(), 403);
        abort_if($message->answer_type !== 'computed' || empty($message->computed_spec), 422, 'This answer cannot be pinned.');

        DashboardWidget::create([
            'user_id' => auth()->id(),
            'title' => $this->buildTitle($message),
            'metric_spec' => $message->computed_spec,
            'chart_type' => $this->chartTypeFor($message->citation_data['type'] ?? 'scalar'),
        ]);

        return redirect()->route('dashboard')->with('status', 'Pinned to dashboard.');
    }

    public function destroy(DashboardWidget $widget)
    {
        abort_if($widget->user_id !== auth()->id(), 403);

        $widget->delete();

        return redirect()->route('dashboard')->with('status', 'Removed from dashboard.');
    }

    private function chartTypeFor(string $resultType): string
    {
        return match ($resultType) {
            'breakdown' => 'bar',
            'listing' => 'table',
            default => 'kpi',
        };
    }

    private function buildTitle(ChatMessage $message): string
    {
        $label = $message->citation_data['label'] ?? 'Custom Stat';
        $groupBy = $message->computed_spec['group_by'] ?? null;

        $title = $groupBy ? "{$label} by {$groupBy}" : $label;

        $filters = $message->computed_spec['filters'] ?? [];
        if (! empty($filters)) {
            $filterText = collect($filters)->map(fn ($v, $k) => "{$k}={$v}")->implode(', ');
            $title .= " ({$filterText})";
        }

        return $title;
    }
}
