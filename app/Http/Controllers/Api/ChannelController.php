<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChannelController extends Controller
{
    // GET /api/channels (public) — powers channel pickers and live-status displays.
    public function index()
    {
        return response()->json(
            Channel::withCount('shows')->orderBy('name')->get()
        );
    }

    // POST /api/admin/channels
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'logo'       => 'nullable|image|max:2048',
            'stream_url' => 'nullable|string|max:2048',
            'is_live'    => 'boolean',
        ]);

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('channels', 'public');
        }

        $channel = Channel::create($data);

        return response()->json($channel, 201);
    }

    // PUT /api/admin/channels/{channel}
    public function update(Request $request, Channel $channel)
    {
        $data = $request->validate([
            'name'       => 'string|max:255',
            'logo'       => 'nullable|image|max:2048',
            'stream_url' => 'nullable|string|max:2048',
            'is_live'    => 'boolean',
        ]);

        if ($request->hasFile('logo')) {
            if ($oldLogo = $channel->getRawOriginal('logo')) {
                Storage::disk('public')->delete($oldLogo);
            }
            $data['logo'] = $request->file('logo')->store('channels', 'public');
        }

        $channel->update($data);

        return response()->json($channel);
    }

    // DELETE /api/admin/channels/{channel}
    public function destroy(Channel $channel)
    {
        if ($channel->shows()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a channel that still has shows or podcasts assigned to it.',
            ], 422);
        }

        if ($logo = $channel->getRawOriginal('logo')) {
            Storage::disk('public')->delete($logo);
        }

        $channel->delete();

        return response()->json(['message' => 'Channel deleted']);
    }
}
