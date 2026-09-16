<?php

namespace App\Services;

use App\Models\Festival;
use Illuminate\Support\Facades\Cache;

/**
 * Centralized cache key naming and helpers for the MandalHisab backend.
 *
 * Uses the Laravel Cache facade with the configured driver (file by default).
 * Because the file driver does not support cache tags, invalidation is done
 * via a key registry: every key written through remember() is recorded, and
 * forgetByPrefix() deletes the recorded keys matching a prefix. This works
 * on any driver (file, redis, array).
 */
class CacheKeyService
{
    const PREFIX = 'mh';

    // Registry of live cache keys, used for prefix-based invalidation.
    const REGISTRY_KEY = self::PREFIX . ':key_registry';
    const REGISTRY_TTL = 86400; // 1 day; entries expire on their own sooner

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
    const TTL_MEMBER_SUMMARY = 180; // 3 min
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

    public static function reportsTyped(string $festivalId, string $type = '', string $paramsHash = ''): string
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

    public static function memberSummary(string $mandalId, string $memberId = '', string $paramsHash = ''): string
    {
        return self::PREFIX . ":members:summary:{$mandalId}:{$memberId}:{$paramsHash}";
    }

    public static function receiptBooksList(string $festivalId, string $paramsHash = ''): string
    {
        return self::PREFIX . ":receipt_books:list:{$festivalId}:{$paramsHash}";
    }

    // ── Prefix builders for family-wide invalidation ─────────────────

    private static function memberSummaryPrefix(string $mandalId): string
    {
        return self::PREFIX . ":members:summary:{$mandalId}:";
    }

    private static function reportsTypedPrefix(string $festivalId): string
    {
        return self::PREFIX . ":reports:typed:{$festivalId}:";
    }

    // ── Hash helper for query params ─────────────────────────────────

    public static function paramsHash(array $params): string
    {
        return md5(json_encode($params));
    }

    // ── Cache wrapper ────────────────────────────────────────────────

    /**
     * Remember a value in cache, or execute the callback and store it.
     * The key is registered so forgetByPrefix() can invalidate it later.
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        self::trackKey($key);

        return Cache::remember($key, $ttl, $callback);
    }

    private static function trackKey(string $key): void
    {
        $keys = Cache::get(self::REGISTRY_KEY, []);

        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::put(self::REGISTRY_KEY, $keys, now()->addSeconds(self::REGISTRY_TTL));
        }
    }

    /**
     * Forget an exact cache key.
     */
    public static function forget(string $key): void
    {
        Cache::forget($key);
    }

    /**
     * Forget all registered keys matching a prefix. Driver-agnostic:
     * relies on the registry populated by remember(), not on tags.
     */
    public static function forgetByPrefix(string $prefix): void
    {
        $keys = Cache::get(self::REGISTRY_KEY, []);

        if ($keys === []) {
            return;
        }

        $remaining = [];
        foreach ($keys as $key) {
            if (str_starts_with($key, $prefix)) {
                Cache::forget($key);
            } else {
                $remaining[] = $key;
            }
        }

        Cache::put(self::REGISTRY_KEY, $remaining, now()->addSeconds(self::REGISTRY_TTL));
    }

    // ── Invalidation helpers ─────────────────────────────────────────

    /**
     * Clear dashboard, funds and report caches for a festival.
     * Call this after any vargani, expense, handover, transfer, or other-income mutation.
     */
    public static function clearDashboardAndFunds(string $festivalId): void
    {
        self::forget(self::dashboard($festivalId));
        self::forget(self::fundsSummary($festivalId));
        self::forget(self::reportsOverview($festivalId));
        self::forget(self::reportsFinalHisab($festivalId));
        self::forgetByPrefix(self::fundsTrail($festivalId));
        self::forgetByPrefix(self::fundsHandovers($festivalId));
        self::forgetByPrefix(self::reportsTypedPrefix($festivalId));
    }

    /**
     * Clear all vargani-related caches (list, funds, member summaries).
     */
    public static function clearVargani(string $festivalId): void
    {
        self::clearDashboardAndFunds($festivalId);
        self::forgetByPrefix(self::varganiList($festivalId));
        self::clearMemberSummariesForFestival($festivalId);
    }

    /**
     * Clear all expense-related caches (list, funds, member summaries).
     */
    public static function clearExpenses(string $festivalId): void
    {
        self::clearDashboardAndFunds($festivalId);
        self::forgetByPrefix(self::expensesList($festivalId));
        self::clearMemberSummariesForFestival($festivalId);
    }

    /**
     * Clear all fund-related caches (after handover or transfer).
     */
    public static function clearFunds(string $festivalId): void
    {
        self::clearDashboardAndFunds($festivalId);
        self::clearMemberSummariesForFestival($festivalId);
    }

    /**
     * Clear all report-related caches.
     */
    public static function clearReports(string $festivalId): void
    {
        self::forget(self::reportsOverview($festivalId));
        self::forget(self::reportsFinalHisab($festivalId));
        self::forgetByPrefix(self::reportsTypedPrefix($festivalId));
    }

    /**
     * Clear all member-related caches (paginated list + per-member summaries).
     */
    public static function clearMembers(string $mandalId): void
    {
        self::forgetByPrefix(self::membersList($mandalId));
        self::forgetByPrefix(self::memberSummaryPrefix($mandalId));
    }

    /**
     * Clear all receipt-book caches.
     */
    public static function clearReceiptBooks(string $festivalId): void
    {
        self::forgetByPrefix(self::receiptBooksList($festivalId));
        self::clearMemberSummariesForFestival($festivalId);
    }

    /**
     * Member summaries cover a whole mandal (optionally scoped to a festival),
     * so a festival-scoped mutation invalidates via the festival's mandal.
     */
    private static function clearMemberSummariesForFestival(string $festivalId): void
    {
        $mandalId = Festival::whereKey($festivalId)->value('mandal_id');

        if ($mandalId) {
            self::forgetByPrefix(self::memberSummaryPrefix((string) $mandalId));
            self::forgetByPrefix(self::membersList((string) $mandalId));
        }
    }
}
