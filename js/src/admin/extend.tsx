import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import type Mithril from 'mithril';
import type Stream from 'flarum/common/utils/Stream';
import type Model from 'flarum/common/Model';
import type Group from 'flarum/common/models/Group';
import type AdminPage from 'flarum/admin/components/AdminPage';
import type { BuzzType } from '../common/types';

/*
 * Buzz's admin: which events, how they look, how they arrive, and privacy.
 * The list of events comes from the server: core's three plus one for each
 * integrated extension that is enabled, and any another extension registers.
 * Settings are read when the admin mounts, so app.data is there by then.
 */

function idList(stream: Stream<string>): string[] {
  try {
    const list = JSON.parse(stream() || '[]');
    return Array.isArray(list) ? list.map(String) : [];
  } catch (e) {
    return [];
  }
}

/** A checkbox list of records (groups, tags) stored as a JSON list of ids. */
function picker<T extends Model>(key: string, label: Mithril.Children, records: () => T[], name: (record: T) => Mithril.Children) {
  return function (this: AdminPage): Mithril.Children {
    const stream = this.setting(key);
    const chosen = idList(stream);
    const toggle = (id: string, on: boolean) => {
      const next = on ? [...new Set([...chosen, id])] : chosen.filter((x) => x !== id);
      stream(JSON.stringify(next));
    };

    return (
      <div className="Form-group BuzzPicker">
        <label>{label}</label>
        <div className="BuzzPicker-list">
          {records().length ? (
            records().map((record) => (
              <label className="checkbox" key={record.id()}>
                <input
                  type="checkbox"
                  checked={chosen.includes(record.id()!)}
                  onchange={(e: Event) => toggle(record.id()!, (e.target as HTMLInputElement).checked)}
                />
                {name(record)}
              </label>
            ))
          ) : (
            <span className="helpText">{app.translator.trans('ernestdefoe-buzz.admin.none')}</span>
          )}
        </div>
      </div>
    );
  };
}

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans('ernestdefoe-buzz.admin.' + key, params);
const types = () => (app.data.buzzTypes || []) as BuzzType[];
const section = (key: string, help?: string) => () => () => (
  <div className="Form-group BuzzSection">
    <h3>{t('sections.' + key)}</h3>
    {help ? <p className="helpText">{t('sections.' + help)}</p> : null}
  </div>
);
const options = (key: string, values: string[]) => Object.fromEntries(values.map((value) => [value, t(key + '.' + value)]));
const number = (key: string, label: string, min: number, max: number, help?: string) => () => ({
  setting: 'ernestdefoe-buzz.' + key,
  type: 'number',
  label: t(label),
  ...(help ? { help: t(help) } : {}),
  min,
  max,
});
const toggle = (key: string, label: string, help?: string) => () => ({
  setting: 'ernestdefoe-buzz.' + key,
  type: 'boolean',
  label: t(label),
  ...(help ? { help: t(help) } : {}),
});
const select = (key: string, label: string, optionsKey: string, values: string[]) => () => ({
  setting: 'ernestdefoe-buzz.' + key,
  type: 'select',
  label: t(label),
  options: options(optionsKey, values),
  default: values[0],
});

export default [
  new Extend.Admin()
    .setting(section('events', 'events_help'))
    // One switch per event type; the list depends on which extensions are enabled.
    .setting(
      () =>
        function (this: AdminPage): Mithril.Children {
          return types().map((type) => this.buildSettingComponent({ setting: type.setting, type: 'boolean', label: type.name, default: true }));
        }
    )

    .setting(section('display'))
    .setting(select('style', 'style_label', 'style', ['toast', 'ticker']))
    .setting(select('ticker_mode', 'ticker_mode_label', 'ticker_mode', ['slide', 'marquee']))
    .setting(select('toast_side', 'side_label', 'side', ['left', 'right']))
    .setting(number('offset_x', 'offset_x_label', 0, 400))
    .setting(number('offset_y', 'offset_y_label', 0, 400))
    .setting(toggle('show_avatar', 'show_avatar_label'))
    .setting(toggle('show_username', 'show_username_label'))
    .setting(toggle('profile_link', 'profile_link_label'))
    .setting(toggle('mask_names', 'mask_names_label', 'mask_names_help'))
    .setting(number('display_seconds', 'display_seconds_label', 3, 30))
    .setting(number('max_toasts', 'max_toasts_label', 1, 5, 'max_toasts_help'))
    .setting(select('pages', 'pages_label', 'pages', ['all', 'home']))
    .setting(toggle('hide_composing', 'hide_composing_label'))

    .setting(section('delivery'))
    .setting(number('interval', 'interval_label', 10, 600, 'interval_help'))
    .setting(number('recent_on_load', 'recent_on_load_label', 0, 3, 'recent_on_load_help'))
    .setting(number('keep_hours', 'keep_hours_label', 1, 720))
    .setting(number('keep_max', 'keep_max_label', 50, 10000))

    .setting(section('privacy'))
    .setting(toggle('opt_in', 'opt_in_label', 'opt_in_help'))
    .setting(() =>
      picker(
        'ernestdefoe-buzz.exclude_groups',
        t('exclude_groups_label'),
        () => app.store.all<Group>('groups').filter((group) => group.id() !== '2'),
        (group) => group.namePlural()
      )
    )
    .setting(() =>
      'flarum-tags' in (flarum.extensions || {})
        ? picker(
            'ernestdefoe-buzz.exclude_tags',
            t('exclude_tags_label'),
            () => app.store.all('tags'),
            (tag) => tag.attribute<string>('name')
          )
        : null
    )
    .setting(() =>
      types().some((type) => type.key === 'purchase')
        ? toggle('purchase_show_buyer', 'purchase_show_buyer_label', 'purchase_show_buyer_help')()
        : null
    ),
];
