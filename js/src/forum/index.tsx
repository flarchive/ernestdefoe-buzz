import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Switch from 'flarum/common/components/Switch';
import FieldSet from 'flarum/common/components/FieldSet';
import type SettingsPage from 'flarum/forum/components/SettingsPage';
import type { ApiPayloadSingle } from 'flarum/common/Store';
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

/** One check: "anything after this?" */
function ask(query: string): Promise<BuzzPoll> {
  return app.request<BuzzPoll>({
    method: 'GET',
    url: `${app.forum.attribute<string>('apiUrl')}/buzz?${query}`,
    // A background check: no redraw, and no alert when it fails, since the
    // next poll backs off and tries again.
    background: true,
    errorHandler: () => undefined,
  });
}

/** Realtime only says "something new"; the poll decides what this viewer may see. */
function onRealtime(ready: (channel: Channel) => void): void {
  if (!('flarum-realtime' in (flarum.extensions || {}))) return;
  try {
    flarum.reg.get('flarum-realtime', 'forum/RealtimeState')?.onPublicChannelReady?.(ready);
  } catch (e) {
    // No realtime: polling alone.
  }
}

/** Asks the server for new events every so often, sooner when realtime says so. */
class Poller {
  cursor: number | null;
  failures = 0;
  timer = 0;
  busy = false;
  socket: Channel | null = null;
  /** False until the first ask, when the page wants recent context. */
  primed: boolean;

  constructor(private cfg: BuzzConfig) {
    this.cursor = cfg.cursor;
    this.primed = !(cfg.recent > 0);
    this.poll = this.poll.bind(this);
  }

  /** Errors back off (30s, 60s … up to 16x); a live socket halves the polling. */
  wait(): number {
    const socketUp = !!this.socket && (app as RealtimeApp).websocket?.connection?.state === 'connected';
    return this.cfg.interval * 1000 * Math.min(16, 2 ** this.failures) * (socketUp ? 2 : 1);
  }

  schedule(ms: number): void {
    clearTimeout(this.timer);
    this.timer = window.setTimeout(this.poll, ms);
  }

  poll(): void {
    if (document.hidden || this.busy) return;
    this.busy = true;
    // The first ask carries no cursor when the page wants recent context or
    // has no cursor yet; the answer says where "now" is.
    const query = this.primed && this.cursor != null ? `after=${this.cursor}` : `recent=${this.cfg.recent}`;
    this.primed = true;

    ask(query)
      .then((body) => {
        this.failures = 0;
        this.cursor = body.cursor;
        if (body.events.length) show(body.events);
      })
      .catch(() => this.failures++)
      .finally(() => {
        this.busy = false;
        this.schedule(this.wait());
      });
  }

  begin(): void {
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) clearTimeout(this.timer);
      else this.schedule(1000);
    });

    if (this.cfg.realtime) {
      onRealtime((channel) => {
        this.socket = channel;
        channel.bind('buzz.new', () => document.hidden || this.schedule(200 + Math.random() * 1500));
      });
    }

    // "Recent" context, or no cursor yet (cache cleared): ask at once. Otherwise
    // the page starts from now and the first poll comes one interval later.
    this.schedule(this.cursor == null || this.cfg.recent > 0 ? 1500 : this.wait());
  }
}

function start(): void {
  const cfg = config();
  const user = app.session.user;
  if (!cfg || (user && user.preferences()?.buzzMute)) return;
  new Poller(cfg).begin();
}

app.initializers.add('ernestdefoe-buzz', () => {
  extend(app, 'mount', start);

  // The answer is only sent when asked for, so the session user costs nothing elsewhere.
  extend<SettingsPage, 'oninit'>('flarum/forum/components/SettingsPage', 'oninit', function () {
    const user = this.user;
    if (!config() || !user || user.attribute('buzzShare') !== undefined) return;
    // Through app.request: core's find() typings take `fields` as a list, not JSON:API's map.
    app
      .request<ApiPayloadSingle>({
        method: 'GET',
        url: `${app.forum.attribute<string>('apiUrl')}/users/${user.id()}`,
        params: { fields: { users: 'buzzShare' } },
      })
      .then((payload) => {
        app.store.pushPayload(payload);
        m.redraw();
      });
  });

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
        <Switch
          state={share === null || share === undefined ? !optIn : share}
          disabled={share === undefined}
          onchange={(value: boolean) => user.save({ buzzShare: value })}
        >
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
