import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { PROJECTS, parseProjectArg, resolveTargets, dispatch } from '../../services/cron/runner.js';

describe('cron runner', () => {
  // ── PROJECTS registry ──────────────────────────────────────────────────

  describe('PROJECTS', () => {
    it('has 333method registered', () => {
      expect(PROJECTS['333method']).toBeDefined();
      expect(PROJECTS['333method'].db).toContain('sites.db');
      expect(PROJECTS['333method'].runner).toContain('cron.js');
      expect(PROJECTS['333method'].root).toContain('333Method');
    });
  });

  // ── parseProjectArg ────────────────────────────────────────────────────

  describe('parseProjectArg', () => {
    it('returns null when no --project arg', () => {
      expect(parseProjectArg(['node', 'runner.js'])).toBeNull();
    });

    it('extracts project from --project=333method', () => {
      expect(parseProjectArg(['node', 'runner.js', '--project=333method'])).toBe('333method');
    });

    it('extracts project from --project=2step', () => {
      expect(parseProjectArg(['node', 'runner.js', '--project=2step'])).toBe('2step');
    });

    it('handles extra args', () => {
      expect(parseProjectArg(['node', 'runner.js', '--verbose', '--project=333method', '--dry-run'])).toBe('333method');
    });
  });

  // ── resolveTargets ─────────────────────────────────────────────────────

  describe('resolveTargets', () => {
    it('returns all projects when no filter', () => {
      const targets = resolveTargets(null);
      expect(targets).toBe(PROJECTS);
    });

    it('returns single project when filtered', () => {
      const targets = resolveTargets('333method');
      expect(Object.keys(targets)).toEqual(['333method']);
      expect(targets['333method']).toBe(PROJECTS['333method']);
    });

    it('returns error for unknown project', () => {
      const targets = resolveTargets('unknown');
      expect(targets.error).toBe('Unknown project: unknown');
      expect(targets.known).toContain('333method');
    });

    it('accepts custom projects registry', () => {
      const custom = { myproj: { db: '/tmp/my.db', runner: '/tmp/run.js', root: '/tmp' } };
      const targets = resolveTargets('myproj', custom);
      expect(targets).toEqual({ myproj: custom.myproj });
    });

    it('returns error for unknown project in custom registry', () => {
      const custom = { myproj: { db: '/tmp/my.db', runner: '/tmp/run.js', root: '/tmp' } };
      const targets = resolveTargets('other', custom);
      expect(targets.error).toBe('Unknown project: other');
      expect(targets.known).toEqual(['myproj']);
    });
  });

  // ── dispatch ───────────────────────────────────────────────────────────

  describe('dispatch', () => {
    let warnSpy, logSpy, errSpy;

    beforeEach(() => {
      warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
      logSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
      errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
      warnSpy.mockRestore();
      logSpy.mockRestore();
      errSpy.mockRestore();
    });

    it('skips project when runner file not found', () => {
      const targets = {
        testproj: { db: '/tmp/test.db', runner: '/tmp/run.js', root: '/tmp' },
      };
      const existsSync = vi.fn().mockReturnValue(false);

      const results = dispatch(targets, { _existsSync: existsSync, _execFileSync: vi.fn() });

      expect(results).toEqual([{ project: 'testproj', status: 'skipped', reason: 'runner_not_found' }]);
      expect(warnSpy).toHaveBeenCalledWith(expect.stringContaining('runner not found'));
    });

    it('skips project when DB file not found', () => {
      const targets = {
        testproj: { db: '/tmp/test.db', runner: '/tmp/run.js', root: '/tmp' },
      };
      // runner exists, db does not
      const existsSync = vi.fn()
        .mockReturnValueOnce(true)   // runner
        .mockReturnValueOnce(false); // db

      const results = dispatch(targets, { _existsSync: existsSync, _execFileSync: vi.fn() });

      expect(results).toEqual([{ project: 'testproj', status: 'skipped', reason: 'db_not_found' }]);
    });

    it('executes runner when both files exist', () => {
      const targets = {
        testproj: { db: '/tmp/test.db', runner: '/tmp/run.js', root: '/tmp' },
      };
      const existsSync = vi.fn().mockReturnValue(true);
      const execFileSync = vi.fn();

      const results = dispatch(targets, { _existsSync: existsSync, _execFileSync: execFileSync });

      expect(execFileSync).toHaveBeenCalledWith(
        process.execPath,
        ['/tmp/run.js'],
        expect.objectContaining({
          cwd: '/tmp',
          stdio: 'inherit',
          timeout: 9 * 60 * 1000,
        })
      );
      expect(results).toEqual([{ project: 'testproj', status: 'completed' }]);
    });

    it('sets correct env vars when executing', () => {
      const targets = {
        testproj: { db: '/data/test.db', runner: '/tmp/run.js', root: '/tmp' },
      };
      const existsSync = vi.fn().mockReturnValue(true);
      const execFileSync = vi.fn();

      dispatch(targets, { _existsSync: existsSync, _execFileSync: execFileSync });

      const envArg = execFileSync.mock.calls[0][2].env;
      expect(envArg.DATABASE_PATH).toBe('/data/test.db');
      expect(envArg.MMO_PROJECT).toBe('testproj');
    });

    it('catches runner errors and continues to next project', () => {
      const targets = {
        proj1: { db: '/tmp/p1.db', runner: '/tmp/p1.js', root: '/tmp' },
        proj2: { db: '/tmp/p2.db', runner: '/tmp/p2.js', root: '/tmp' },
      };
      const existsSync = vi.fn().mockReturnValue(true);
      const execFileSync = vi.fn()
        .mockImplementationOnce(() => { throw new Error('exit code 1'); })
        .mockImplementationOnce(() => {});

      const results = dispatch(targets, { _existsSync: existsSync, _execFileSync: execFileSync });

      expect(results).toEqual([
        { project: 'proj1', status: 'error', error: 'exit code 1' },
        { project: 'proj2', status: 'completed' },
      ]);
      // Both were attempted
      expect(execFileSync).toHaveBeenCalledTimes(2);
    });

    it('dispatches multiple projects in order', () => {
      const targets = {
        alpha: { db: '/tmp/a.db', runner: '/tmp/a.js', root: '/tmp' },
        beta: { db: '/tmp/b.db', runner: '/tmp/b.js', root: '/tmp' },
        gamma: { db: '/tmp/c.db', runner: '/tmp/c.js', root: '/tmp' },
      };
      const existsSync = vi.fn().mockReturnValue(true);
      const execFileSync = vi.fn();

      const results = dispatch(targets, { _existsSync: existsSync, _execFileSync: execFileSync });

      expect(results.map(r => r.project)).toEqual(['alpha', 'beta', 'gamma']);
      expect(results.every(r => r.status === 'completed')).toBe(true);
    });
  });
});
