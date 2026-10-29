import app from 'flarum/admin/app';

/*
 * Buzz's admin: which events, how they look, how they arrive, and privacy.
 * The list of events comes from the server: core's three plus one for each
 * integrated extension that is enabled, and any another extension registers.
 */

function idList(stream) {
  try {
    const list = JSON.parse(stream() || '[]');
    return Array.isArray(list) ? list.map(String) : [];
  } catch (e) {
    return [];
  }
}

function picker(key, label, records, name) {
  return function () {
    const stream = this.setting(key);
    const chosen = idList(stream);
    const toggle = (id, on) => {
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
                <input type="checkbox" checked={chosen.includes(record.id())} onchange={(e) => toggle(record.id(), e.target.checked)} />
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

app.initializers.add('ernestdefoe-buzz', () => {
  const t = (key, params) => app.translator.trans('ernestdefoe-buzz.admin.' + key, params);
  const section = (key, help) => () => (
    <div className="Form-group BuzzSection">
      <h3>{t('sections.' + key)}</h3>
      {help ? <p className="helpText">{t('sections.' + help)}</p> : null}
    </div>
  );
  const options = (key, values) => Object.fromEntries(values.map((value) => [value, t(key + '.' + value)]));
  const reg = app.registry.for('ernestdefoe-buzz');

  reg.registerSetting(section('events', 'events_help'));
  (app.data.buzzTypes || []).forEach((type) => reg.registerSetting({ setting: type.setting, type: 'boolean', label: type.name, default: true }));

  reg
    .registerSetting(section('display'))
    .registerSetting({
      setting: 'ernestdefoe-buzz.style',
      type: 'select',
      label: t('style_label'),
      options: options('style', ['toast', 'ticker']),
      default: 'toast',
    })
    .registerSetting({
      setting: 'ernestdefoe-buzz.ticker_mode',
      type: 'select',
      label: t('ticker_mode_label'),
      options: options('ticker_mode', ['slide', 'marquee']),
      default: 'slide',
    })
    .registerSetting({
      setting: 'ernestdefoe-buzz.toast_side',
      type: 'select',
      label: t('side_label'),
      options: options('side', ['left', 'right']),
      default: 'left',
    })
    .registerSetting({ setting: 'ernestdefoe-buzz.offset_x', type: 'number', label: t('offset_x_label'), min: 0, max: 400 })
    .registerSetting({ setting: 'ernestdefoe-buzz.offset_y', type: 'number', label: t('offset_y_label'), min: 0, max: 400 })
    .registerSetting({ setting: 'ernestdefoe-buzz.show_avatar', type: 'boolean', label: t('show_avatar_label') })
    .registerSetting({ setting: 'ernestdefoe-buzz.show_username', type: 'boolean', label: t('show_username_label') })
    .registerSetting({ setting: 'ernestdefoe-buzz.profile_link', type: 'boolean', label: t('profile_link_label') })
    .registerSetting({ setting: 'ernestdefoe-buzz.mask_names', type: 'boolean', label: t('mask_names_label'), help: t('mask_names_help') })
    .registerSetting({ setting: 'ernestdefoe-buzz.display_seconds', type: 'number', label: t('display_seconds_label'), min: 3, max: 30 })
    .registerSetting({
      setting: 'ernestdefoe-buzz.max_toasts',
      type: 'number',
      label: t('max_toasts_label'),
      help: t('max_toasts_help'),
      min: 1,
      max: 5,
    })
    .registerSetting({
      setting: 'ernestdefoe-buzz.pages',
      type: 'select',
      label: t('pages_label'),
      options: options('pages', ['all', 'home']),
      default: 'all',
    })
    .registerSetting({ setting: 'ernestdefoe-buzz.hide_composing', type: 'boolean', label: t('hide_composing_label') })

    .registerSetting(section('delivery'))
    .registerSetting({
      setting: 'ernestdefoe-buzz.interval',
      type: 'number',
      label: t('interval_label'),
      help: t('interval_help'),
      min: 10,
      max: 600,
    })
    .registerSetting({
      setting: 'ernestdefoe-buzz.recent_on_load',
      type: 'number',
      label: t('recent_on_load_label'),
      help: t('recent_on_load_help'),
      min: 0,
      max: 3,
    })
    .registerSetting({ setting: 'ernestdefoe-buzz.keep_hours', type: 'number', label: t('keep_hours_label'), min: 1, max: 720 })
    .registerSetting({ setting: 'ernestdefoe-buzz.keep_max', type: 'number', label: t('keep_max_label'), min: 50, max: 10000 })

    .registerSetting(section('privacy'))
    .registerSetting({ setting: 'ernestdefoe-buzz.opt_in', type: 'boolean', label: t('opt_in_label'), help: t('opt_in_help') })
    .registerSetting(
      picker(
        'ernestdefoe-buzz.exclude_groups',
        t('exclude_groups_label'),
        () => app.store.all('groups').filter((group) => group.id() !== '2'),
        (group) => group.namePlural()
      )
    );

  if ('flarum-tags' in (flarum.extensions || {})) {
    reg.registerSetting(
      picker(
        'ernestdefoe-buzz.exclude_tags',
        t('exclude_tags_label'),
        () => app.store.all('tags'),
        (tag) => tag.name()
      )
    );
  }

  if ((app.data.buzzTypes || []).some((type) => type.key === 'purchase')) {
    reg.registerSetting({
      setting: 'ernestdefoe-buzz.purchase_show_buyer',
      type: 'boolean',
      label: t('purchase_show_buyer_label'),
      help: t('purchase_show_buyer_help'),
    });
  }
});
