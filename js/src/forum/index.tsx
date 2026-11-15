import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Switch from 'flarum/common/components/Switch';
import FieldSet from 'flarum/common/components/FieldSet';
import type SettingsPage from 'flarum/forum/components/SettingsPage';
import type Display from './Display';
import type { BuzzConfig, BuzzEvent, BuzzPoll } from '../common/types';

/** What flarum/realtime adds: the socket, and a public channel to listen on. */
type RealtimeApp = typeof app & { websocket?: { connection?: { state?: string } } };
interface Channel {
  bind(event: string, callback: () => void): void;
}

const config = (): BuzzConfig | null => app.forum.attribute<BuzzConfig | null>('buzz');

/*
 * What every page carries: a poller and the member's two switches. The part
 * that draws events is a separate chunk, fetched when the first one arrives.
 */

/** null: not loaded yet; false: loading. */
let display: Display | null | false = null;
const pending: BuzzEvent[] = [];

function show(events: BuzzEvent[]): void {
  pending.push(...events);
  if (display) return display.push(pending.splice(0));
  if (display === false) return;
  display = false;
  import('./Display').then(({ default: Display }) => {
    const shown = (display = new Display(config()!));
    shown.push(pending.splice(0));
  });
}

function start(): void {
  const found = config();
  const user = app.session.user;
  if (!found || (user && user.preferences()?.buzzMute)) return;
  const cfg = found;

  const url = app.forum.attribute<string>('apiUrl') + '/buzz';
  let cursor = cfg.cursor;
  let failures = 0;
  let timer = 0;
  let busy = false;
  let socket: Channel | null = null;
  let primed = !(cfg.recent > 0);

  const socketUp = () => !!socket && (app as RealtimeApp).websocket?.connection?.state === 'connected';
  // Errors back off (30s, 60s … up to 16x); a live socket halves the polling.
  const wait = () => cfg.interval * 1000 * Math.min(16, 2 ** failures) * (socketUp() ? 2 : 1);
  const schedule = (ms: number) => {
    clearTimeout(timer);
    timer = window.setTimeout(poll, ms);
  };

  function poll(): void {
    if (document.hidden || busy) return;
    busy = true;
    // The first ask carries no cursor when the page wants recent context or
    // has no cursor yet; the answer says where "now" is.
    const query = primed && cursor != null ? `after=${cursor}` : `recent=${cfg.recent}`;
    primed = true;

    fetch(`${url}?${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then((body: BuzzPoll) => {
        failures = 0;
        cursor = body.cursor;
        if (body.events.length) show(body.events);
      })
      .catch(() => failures++)
      .finally(() => {
        busy = false;
        schedule(wait());
      });
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) clearTimeout(timer);
    else schedule(1000);
  });

  // Realtime only says "something new"; the poll decides what this viewer may see.
  if (cfg.realtime && 'flarum-realtime' in (flarum.extensions || {})) {
    try {
      const rt = flarum.reg.get('flarum-realtime', 'forum/RealtimeState');
      rt?.onPublicChannelReady?.((channel: Channel) => {
        socket = channel;
        channel.bind('buzz.new', () => document.hidden || schedule(200 + Math.random() * 1500));
      });
    } catch (e) {
      // No realtime: polling alone.
    }
  }

  // "Recent" context, or no cursor yet (cache cleared): ask at once. Otherwise
  // the page starts from now and the first poll comes one interval later.
  schedule(cursor == null || cfg.recent > 0 ? 1500 : wait());
}

app.initializers.add('ernestdefoe-buzz', () => {
  extend(app, 'mount', start);

  // A string: the settings page is its own lazily loaded chunk in Flarum 2.
  extend<SettingsPage, 'settingsItems'>('flarum/forum/components/SettingsPage', 'settingsItems', function (items) {
    const cfg = config();
    if (!cfg) return;
    const user = this.user!;
    const optIn = cfg.optIn;
    const share = user.attribute<boolean | null | undefined>('buzzShare');
    const t = (key: string) => app.translator.trans('ernestdefoe-buzz.forum.settings.' + key);

    items.add(
      'buzz',
      <FieldSet className="Settings-buzz" label={t('heading')}>
        <Switch state={share === null || share === undefined ? !optIn : share} onchange={(value: boolean) => user.save({ buzzShare: value })}>
          {t('share_label')}
        </Switch>
        <div className="helpText">{t('share_help')}</div>
        <Switch state={!!user.preferences()?.buzzMute} onchange={(value: boolean) => user.savePreferences({ buzzMute: value })}>
          {t('mute_label')}
        </Switch>
        <div className="helpText">{t('mute_help')}</div>
      </FieldSet>,
      -10
    );
  });
});
