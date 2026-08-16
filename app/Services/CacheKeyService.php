<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Centralized cache key naming and helpers for the MandalHisab backend.
 *
 * Uses the Laravel Cache facade with the configured driver (file by default).
 * Because the file driver does not support cache tags, invalidation is done
 * by exact key deletion and by iterating known prefixes.
 */
class CacheKeyService
{
    const PREFIX = 'mh';

    // TTL values in seconds
    const TTL_DASHBOARD = 180;      // 3 min
    const TTL_FUNDS_SUMMARY = 180;  // 3 min
    const TTL_FUNDS_TRAIL = 120;    // 2 min
    const TTL_FUNDS_HANDOVERS = 120;// 2 min
    const TTL_REPORTS_OVERVIEW = 300;   // 5 min
    const TTL_REPORTS_TYPED = 300;      // 5 min
    const TTL_REPORTS_FINAL_HISAB = 600;// 10 min
    const TTL_VARGANI_LIST = 120;   // 2 min
    const TTL_EXPENSES_LIST = 120;  // 2 min
    const TTL_MEMBERS_LIST = 300;   // 5 min
    const TTL_RECEIPT_BOOKS = 600;  // 10 min

    // ── Key generators ─────────────────────────────────────────────

    public static function dashboard(string $festivalId): string
    {
        return self::PREFIX . ":dashboard:{$festivalId}";
    }

    public static function fundsSummary(string $festivalId): string
    {
        return self::PREFIX . ":funds:summary:{$festivalId}";
    }

    public static function fundsTrail(string $festivalId, string $paramsHash = ''): string
    {
        return self::PREFIX . ":funds:trail:{$festivalId}:{$paramsHash}";
    }

    public static function fundsHandovers(string $festivalId, string $paramsHash = ''): string
    {
        return self::PREFIX . ":funds:handovers:{$festivalId}:{$paramsHash}";
    }

    public static function reportsOverview(string $festivalId): string
    {
        return self::PREFIX . ":reports:overview:{$festivalId}";
    }

    public static function reportsTyped(string $festivalId, string $type, string $paramsHash = ''): string
    {
        return self::PREFIX . ":reports:typed:{$festivalId}:{$type}:{$paramsHash}";
    }

    public static function reportsFinalHisab(string $festivalId): string
    {
        return self::PREFIX . ":reports:final_hisab:{$festivalId}";
    }

    public static function varganiList(string $festivalId, string $paramsHash = ''): string
    {
        return self::PREFIX . ":vargani:list:{$festivalId}:{$paramsHash}";
    }

    public static function expensesList(string $festivalId, string $paramsHash = ''): string
    {
        return self::PREFIX . ":expenses:list:{$festivalId}:{$paramsHash}";
    }

    public static function membersList(string $mandalId, string $paramsHash = ''): string
    {
        return self::PREFIX . ":members:list:{$mandalId}:{$paramsHash}";
    }

    public static function receiptBooksList(string $festivalId, string $paramsHash = ''): string
    {
        return self::PREFIX . ":receipt_books:list:{$festivalId}:{$paramsHash}";
    }

    // ── Hash helper for query params ─────────────────────────────────

    public static function paramsHash(array $params): string
    {
        return md5(json_encode($params));
    }

    // ── Cache wrapper ────────────────────────────────────────────────

    /**
     * Remember a value in cache, or execute the callback and store it.
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Forget an exact cache key.
     */
    public static function forget(string $key): void
    {
        Cache::forget($key);
    }

    /**
     * Forget all keys matching a prefix pattern.
     *
     * Note: The file driver does not support key scanning or tags, so this
     * method is a no-op placeholder. In production with Redis, replace
     * this with Cache::tags($tag)->flush() or a SCAN-based deletion.
     */
    public static function forgetByPrefix(string $prefix): void
    {
        // No-op on file driver. Exact-key clearing is used in controllers.
    }

    // ── Invalidation helpers ─────────────────────────────────────────

    /**
     * Clear all dashboard and fund-related caches for a festival.
     * Call this after any vargani, expense, handover, transfer, or other-income mutation.
     */
    public static function clearDashboardAndFunds(string $festivalId): void
    {
        self::forget(self::dashboard($festivalId));
        self::forget(self::fundsSummary($festivalId));
        self::forget(self::reportsOverview($festivalId));
        self::forget(self::reportsFinalHisab($festivalId));
        // We cannot wildcard-clear funds:trail:* on file driver,
        // so we accept stale trail data for up to 2 minutes.
    }

    /**
     * Clear all vargani-related caches.
     */
    public static function clearVargani(string $festivalId): void
    {
        self::clearDashboardAndFunds($festivalId);
        // Cannot wildcard vargani:list:* on file driver.
    }

    /**
     * Clear all expense-related caches.
     */
    public static function clearExpenses(string $festivalId): void
    {
        self::clearDashboardAndFunds($festivalId);
    }

    /**
     * Clear all fund-related caches (after handover or transfer).
     *
     * Note: Paginated handover list caches cannot be invalidated on the
     * file driver (no wildcard support). They expire naturally via TTL.
     */
    public static function clearFunds(string $festivalId): void
    {
        self::clearDashboardAndFunds($festivalId);
    }

    /**
     * Clear all report-related caches.
     */
    public static function clearReports(string $festivalId): void
    {
        self::forget(self::reportsOverview($festivalId));
        self::forget(self::reportsFinalHisab($festivalId));
    }

    /**
     * Clear all member-related caches.
     *
     * Note: Paginated member list caches cannot be invalidated on the
     * file driver (no wildcard support). They expire naturally via TTL.
     */
    public static function clearMembers(string $mandalId): void
    {
        // No exact key to forget for paginated list caches.
    }

    /**
     * Clear all receipt-book caches.
     *
     * Note: Paginated receipt-book list caches cannot be invalidated on the
     * file driver (no wildcard support). They expire naturally via TTL.
     */
    public static function clearReceiptBooks(string $festivalId): void
    {
        // No exact key to forget for paginated list caches.
    }
}
