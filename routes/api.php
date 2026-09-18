<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ShowController;
use App\Http\Controllers\Api\WatchHistoryController;
use App\Http\Controllers\Api\WatchlistController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\HeroSlideController;
use App\Http\Controllers\Api\GenreController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\PromoCardController;
use App\Http\Controllers\Api\V1\ResearcherSearchController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\AdController;

// ─── Public routes ────────────────────────────────────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// Shows are public (browsable without login)
Route::get('/shows',     [ShowController::class, 'index']);
Route::get('/shows/{show}', [ShowController::class, 'show']);
Route::get('/hero-slides', [HeroSlideController::class, 'index']);
Route::get('/genres', [GenreController::class, 'index']);
Route::get('/tags',   [TagController::class,   'index']);
Route::get('/channels', [ChannelController::class, 'index']);
Route::get('/promo-cards', [PromoCardController::class, 'index']);
Route::get('/plans', [PlanController::class, 'index']);
Route::get('/ads/active', [AdController::class, 'active']);

// ─── Authenticated routes ─────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);
    Route::put('/me',          [AuthController::class, 'updateProfile']);
    Route::put('/me/password', [AuthController::class, 'updatePassword']);

    // Watch history (continue watching)
    Route::get('/watch-history',            [WatchHistoryController::class, 'index']);
    Route::post('/watch-history',           [WatchHistoryController::class, 'store']);
    Route::delete('/watch-history/{showId}',[WatchHistoryController::class, 'destroy']);

    // Watchlist (watch later)
    Route::get('/watchlist',                    [WatchlistController::class, 'index']);
    Route::post('/watchlist',                   [WatchlistController::class, 'store']);
    Route::delete('/watchlist/{showId}',        [WatchlistController::class, 'destroy']);
    Route::get('/watchlist/check/{showId}',     [WatchlistController::class, 'check']);

    // Subscriptions (payment-free for now — a plan pick just activates immediately)
    Route::get('/subscriptions/current', [SubscriptionController::class, 'current']);
    Route::post('/subscriptions',        [SubscriptionController::class, 'store']);

    // Researcher Portal — gated behind auth since each search costs an OpenAI call.
    Route::prefix('v1/researcher')->group(function () {
        Route::post('/search', [ResearcherSearchController::class, 'search']);
    });

    // Admin routes (add IsAdmin middleware later)


});

// Admin routes
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/users',                          [AdminController::class, 'users']);
    Route::post('/episodes/{showId}',             [AdminController::class, 'addEpisode']);
    Route::put('/episodes/{episode}',             [AdminController::class, 'updateEpisode']);
    Route::delete('/episodes/{episode}',          [AdminController::class, 'deleteEpisode']);
    Route::get('/hero-slides',                    [HeroSlideController::class, 'adminIndex']);
    Route::post('/hero-slides',                   [HeroSlideController::class, 'store']);
    Route::delete('/hero-slides/{heroSlide}',     [HeroSlideController::class, 'destroy']);
    Route::put('/hero-slides/reorder',            [HeroSlideController::class, 'reorder']);
    Route::put('/hero-slides/{heroSlide}',        [HeroSlideController::class, 'update']);
    Route::post('/shows',           [ShowController::class, 'store']);
    Route::put('/shows/{show}',     [ShowController::class, 'update']);
    Route::delete('/shows/{show}',  [ShowController::class, 'destroy']);
    Route::get('/genres',  [GenreController::class, 'index']);
    Route::post('/genres', [GenreController::class, 'store']);
    Route::delete('/genres/{genre}', [GenreController::class, 'destroy']);
    Route::post('/genres/{genre}/attach', [GenreController::class, 'attachShow']);
    Route::post('/genres/{genre}/detach', [GenreController::class, 'detachShow']);

    Route::get('/tags',  [TagController::class, 'index']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::delete('/tags/{tag}', [TagController::class, 'destroy']);
    Route::post('/tags/{tag}/attach', [TagController::class, 'attachShow']);
    Route::post('/tags/{tag}/detach', [TagController::class, 'detachShow']);

    Route::get('/channels',            [ChannelController::class, 'index']);
    Route::post('/channels',           [ChannelController::class, 'store']);
    Route::put('/channels/{channel}',  [ChannelController::class, 'update']);
    Route::delete('/channels/{channel}', [ChannelController::class, 'destroy']);

    Route::get('/promo-cards',               [PromoCardController::class, 'index']);
    Route::post('/promo-cards',              [PromoCardController::class, 'store']);
    Route::put('/promo-cards/{promoCard}',   [PromoCardController::class, 'update']);
    Route::delete('/promo-cards/{promoCard}', [PromoCardController::class, 'destroy']);

    Route::get('/ads',            [AdController::class, 'index']);
    Route::post('/ads',           [AdController::class, 'store']);
    Route::put('/ads/{ad}',       [AdController::class, 'update']);
    Route::delete('/ads/{ad}',    [AdController::class, 'destroy']);
});