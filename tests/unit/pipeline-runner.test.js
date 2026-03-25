import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  safeImport,
  safeRun,
  requestShutdown,
  isShuttingDown,
  resetShutdown,
  runIteration,
} from '../../src/pipeline-runner.js';

describe('pipeline-runner', () => {
  beforeEach(() => {
    resetShutdown();
  });

  // ── requestShutdown / isShuttingDown ───────────────────────────────────

  describe('requestShutdown', () => {
    it('sets shuttingDown to true', () => {
      expect(isShuttingDown()).toBe(false);
      requestShutdown('SIGINT');
      expect(isShuttingDown()).toBe(true);
    });

    it('is idempotent — second call is a no-op', () => {
      const spy = vi.spyOn(console, 'log').mockImplementation(() => {});
      requestShutdown('SIGINT');
      requestShutdown('SIGTERM');
      // Only one log (first call)
      expect(spy).toHaveBeenCalledTimes(1);
      spy.mockRestore();
    });

    it('logs the signal name', () => {
      const spy = vi.spyOn(console, 'log').mockImplementation(() => {});
      requestShutdown('SIGTERM');
      expect(spy).toHaveBeenCalledWith(expect.stringContaining('SIGTERM'));
      spy.mockRestore();
    });
  });

  describe('resetShutdown', () => {
    it('resets shuttingDown back to false', () => {
      requestShutdown('SIGINT');
      expect(isShuttingDown()).toBe(true);
      resetShutdown();
      expect(isShuttingDown()).toBe(false);
    });
  });

  // ── safeImport ─────────────────────────────────────────────────────────

  describe('safeImport', () => {
    it('returns null for non-existent module', async () => {
      const spy = vi.spyOn(console, 'warn').mockImplementation(() => {});
      const result = await safeImport('/non/existent/module.js', 'default');
      expect(result).toBeNull();
      expect(spy).toHaveBeenCalledWith(expect.stringContaining('Could not import'));
      spy.mockRestore();
    });

    it('imports a real module and returns named export', async () => {
      const result = await safeImport('node:path', 'resolve');
      expect(result).toBeTypeOf('function');
    });

    it('falls back to default export when named export not found', async () => {
      // node:path has a default export
      const result = await safeImport('node:path', 'nonExistentExport');
      expect(result).not.toBeNull();
    });
  });

  // ── safeRun ────────────────────────────────────────────────────────────

  describe('safeRun', () => {
    it('returns skipped:not_loaded when fn is null', async () => {
      const result = await safeRun('test-stage', null);
      expect(result).toEqual({ skipped: 'not_loaded' });
    });

    it('returns skipped:shutdown when shutting down', async () => {
      requestShutdown('test');
      const fn = vi.fn();
      const result = await safeRun('test-stage', fn);
      expect(result).toEqual({ skipped: 'shutdown' });
      expect(fn).not.toHaveBeenCalled();
    });

    it('runs the function and returns ok result', async () => {
      const spy = vi.spyOn(console, 'log').mockImplementation(() => {});
      const fn = vi.fn().mockResolvedValue({ processed: 5 });

      const result = await safeRun('test-stage', fn, { limit: 10 });

      expect(result.ok).toBe(true);
      expect(result.result).toEqual({ processed: 5 });
      expect(result.elapsed).toBeDefined();
      expect(fn).toHaveBeenCalledWith({ limit: 10 });
      spy.mockRestore();
    });

    it('catches errors and returns ok:false', async () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
      const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      const fn = vi.fn().mockRejectedValue(new Error('DB locked'));

      const result = await safeRun('test-stage', fn);

      expect(result.ok).toBe(false);
      expect(result.error).toBe('DB locked');
      expect(result.elapsed).toBeDefined();
      logSpy.mockRestore();
      errSpy.mockRestore();
    });

    it('passes options to the function', async () => {
      const spy = vi.spyOn(console, 'log').mockImplementation(() => {});
      const fn = vi.fn().mockResolvedValue(null);

      await safeRun('test', fn, { limit: 50, methods: ['email'] });

      expect(fn).toHaveBeenCalledWith({ limit: 50, methods: ['email'] });
      spy.mockRestore();
    });
  });

  // ── runIteration ───────────────────────────────────────────────────────

  describe('runIteration', () => {
    let logSpy;

    beforeEach(() => {
      logSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
    });

    afterEach(() => {
      logSpy.mockRestore();
    });

    it('runs all project stages when no filter', async () => {
      const mockImport = vi.fn().mockResolvedValue(null);
      const mockRun = vi.fn();

      const summary = await runIteration(null, {
        _safeImport: mockImport,
        _safeRun: mockRun,
      });

      // Should attempt imports for 333method, 2step, and shared stages
      expect(mockImport).toHaveBeenCalledTimes(4); // 1 (333m) + 2 (2step) + 1 (shared)
      expect(summary).toEqual({});
    });

    it('filters to only 333method stages', async () => {
      const mockImport = vi.fn().mockResolvedValue(null);

      await runIteration('333method', {
        _safeImport: mockImport,
        _safeRun: vi.fn(),
      });

      // Only 333method stage imports
      expect(mockImport).toHaveBeenCalledTimes(1);
      expect(mockImport.mock.calls[0][1]).toBe('runEnrichmentStage');
    });

    it('filters to only 2step stages', async () => {
      const mockImport = vi.fn().mockResolvedValue(null);

      await runIteration('2step', {
        _safeImport: mockImport,
        _safeRun: vi.fn(),
      });

      // 2step: enrich + video = 2 imports
      expect(mockImport).toHaveBeenCalledTimes(2);
    });

    it('filters to only shared stages', async () => {
      const mockImport = vi.fn().mockResolvedValue(null);

      await runIteration('shared', {
        _safeImport: mockImport,
        _safeRun: vi.fn(),
      });

      expect(mockImport).toHaveBeenCalledTimes(1);
      expect(mockImport.mock.calls[0][1]).toBe('runOutreachStage');
    });

    it('runs stages that were successfully imported', async () => {
      const fakeFn = vi.fn();
      const mockImport = vi.fn().mockResolvedValue(fakeFn);
      const mockRun = vi.fn().mockResolvedValue({ ok: true, result: { sent: 5 } });

      const summary = await runIteration('shared', {
        _safeImport: mockImport,
        _safeRun: mockRun,
      });

      expect(mockRun).toHaveBeenCalledWith('shared:outreach', fakeFn, {
        limit: 50,
        methods: ['email', 'sms'],
      });
      expect(summary['shared:outreach']).toEqual({ ok: true, result: { sent: 5 } });
    });

    it('skips stages that failed to import', async () => {
      const mockImport = vi.fn().mockResolvedValue(null);
      const mockRun = vi.fn();

      const summary = await runIteration('333method', {
        _safeImport: mockImport,
        _safeRun: mockRun,
      });

      // safeImport returned null, so safeRun should not be called
      expect(mockRun).not.toHaveBeenCalled();
      expect(summary).toEqual({});
    });

    it('logs iteration start and complete', async () => {
      await runIteration('333method', {
        _safeImport: vi.fn().mockResolvedValue(null),
        _safeRun: vi.fn(),
      });

      const calls = logSpy.mock.calls.map(c => c[0]);
      expect(calls.some(c => c.includes('Iteration at'))).toBe(true);
      expect(calls.some(c => c.includes('Iteration complete'))).toBe(true);
    });
  });
});
