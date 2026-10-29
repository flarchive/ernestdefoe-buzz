import app from 'flarum/forum/app';
import stringToColor from 'flarum/common/utils/stringToColor';
import extractText from 'flarum/common/utils/extractText';

/*
 * Draws Buzz events: one at a time, each for a minimum time, bursts of the
 * same kind collapsed into "+N more", paused while hovered or focused.
 *
 * 🚨 Everything a member wrote (titles, names) goes in as TEXT
 * (textContent), never as HTML. Links come only from app.route() or from a
 * path on this forum.
 */

const MARK = '\u0001';
const SUBJECT = '\u0002';

/** Text with its subject (a title, a rank…) in bold: the server marks it with SUBJECT. */
function withSubject(node, text) {
  String(text)
    .split(SUBJECT)
    .forEach((part, i) => part && node.appendChild(i % 2 ? el('strong', 'Buzz-subject', part) : document.createTextNode(part)));
  return node;
}
const MAX_QUEUE = 20;
const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

// Things fixed to the bottom of the screen a toast must sit above.
// Cookie banners too: ernestdefoe/advanced-cookie-consent's, and the common
// third-party ones (Cookie Consent, OneTrust, Cookiebot, generic ids).
const OBSTACLES = [
  '.AlertManager .Alert, .Composer, .MobileTab-bar, .pl-peek, .pl-dock > *, #bespoke-totop, .Bespoke-toTop',
  '.CookieBanner, .cc-window, .cc-banner, #onetrust-banner-sdk, #CybotCookiebotDialog, #cookie-banner, .cookie-banner',
].join(', ');

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function trans(key, params) {
  return extractText(app.translator.trans('ernestdefoe-buzz.forum.' + key, params));
}

function hrefFor(event) {
  const route = event.route;
  if (route && app.routes[route.name]) {
    try {
      return app.route(route.name, { ...route.params });
    } catch (e) {
      // A route whose parameters no longer fit: no link.
    }
  }
  if (typeof event.url === 'string' && /^\/(?!\/)/.test(event.url)) return app.forum.attribute('basePath') + event.url;
  return null;
}

function profileHref(user) {
  return user && app.routes.user ? app.route('user', { username: user.slug }) : null;
}

function navigate(e, href) {
  if (!href || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  e.preventDefault();
  m.route.set(href);
}

export default class Display {
  constructor(cfg) {
    this.cfg = cfg;
    this.queue = [];
    this.current = null;
    this.timer = 0;
    this.paused = false;
    this.marquee = cfg.style === 'ticker' && cfg.tickerMode === 'marquee' && !reducedMotion();

    const root = (this.root = el('div', `Buzz Buzz--${cfg.style}` + (cfg.style === 'toast' ? ` Buzz--${cfg.side}` : '')));
    root.setAttribute('role', 'status');
    root.setAttribute('aria-live', 'polite');
    root.setAttribute('aria-label', trans('label'));
    root.hidden = true;
    root.style.setProperty('--buzz-x', cfg.offsetX + 'px');
    root.style.setProperty('--buzz-y', cfg.offsetY + 'px');

    root.addEventListener('mouseenter', () => this.pause(true));
    root.addEventListener('mouseleave', () => this.pause(false));
    root.addEventListener('focusin', () => this.pause(true));
    root.addEventListener('focusout', () => this.pause(false));

    document.body.appendChild(root);

    this.place = this.place.bind(this);
    window.addEventListener('resize', this.place, { passive: true });
    if (cfg.style === 'ticker') window.addEventListener('scroll', this.place, { passive: true });
  }

  /** Events arrive oldest first. */
  push(events) {
    if (!this.allowedHere()) return;
    this.queue.push(...events);
    if (this.queue.length > MAX_QUEUE) this.queue.splice(0, this.queue.length - MAX_QUEUE);
    if (this.cfg.style === 'toast') return this.fill();
    if (!this.current) this.next();
  }

  // ── Bottom toast: a stack of small cards, as Invision's Live Activity ─────

  /** Cards on screen at once: the admin's number on a computer, one on a phone. */
  maxToasts() {
    return window.innerWidth < 768 ? 1 : Math.max(1, this.cfg.maxToasts || 2);
  }

  /** Bring on queued events while there is room, oldest first; new cards slide up at the bottom. */
  fill() {
    clearTimeout(this.fillTimer);
    if (!this.allowedHere()) {
      this.queue = [];
      return;
    }
    // Writing a post: wait, and look again in a moment.
    if (this.held()) {
      this.fillTimer = setTimeout(() => this.fill(), 2000);
      return;
    }
    this.active = this.active || [];
    while (this.active.length < this.maxToasts() && this.queue.length) this.showToast(this.queue.shift());
  }

  showToast(event) {
    const item = { node: this.toast(event) };
    this.active.push(item);
    this.root.appendChild(item.node);
    this.root.hidden = false;
    this.place();
    clearInterval(this.watch);
    // Alerts, the composer and chat panels come and go while a card is up.
    this.watch = setInterval(this.place, 500);
    // Next frame, so the entrance transition runs.
    requestAnimationFrame(() => item.node.classList.add('is-in'));
    this.time(item, this.cfg.seconds * 1000);
  }

  time(item, ms) {
    item.remaining = ms;
    item.startedAt = Date.now();
    if (!this.paused) item.timer = setTimeout(() => this.dismiss(item), ms);
  }

  dismiss(item) {
    clearTimeout(item.timer);
    const i = this.active.indexOf(item);
    if (i === -1) return;
    this.active.splice(i, 1);
    item.node.classList.remove('is-in');
    setTimeout(
      () => {
        item.node.remove();
        if (!this.active.length && !this.queue.length) this.hide();
        this.place();
      },
      reducedMotion() ? 0 : 250
    );
    this.fill();
  }

  /** One card: avatar (with the event's icon), the name on one line, what happened on the next. */
  toast(event) {
    const cfg = this.cfg;
    const href = hrefFor(event);
    const profile = cfg.profileLink && cfg.username ? profileHref(event.user) : null;
    const card = el('div', 'Buzz-toast');
    const text = String(event.text);
    const named = text.includes(MARK);

    const face = el('span', 'Buzz-face');
    if (cfg.avatar && event.user) {
      const avatar = event.user.avatar ? el('img', 'Buzz-avatar') : el('span', 'Buzz-avatar', (event.user.name || '?').charAt(0).toUpperCase());
      if (event.user.avatar) {
        avatar.src = event.user.avatar;
        avatar.alt = '';
        avatar.loading = 'lazy';
      } else {
        avatar.style.setProperty('--buzz-avatar', '#' + stringToColor(event.user.name || ''));
      }
      face.appendChild(avatar);
      const badge = el('i', 'Buzz-badge ' + event.icon);
      badge.setAttribute('aria-hidden', 'true');
      face.appendChild(badge);
    } else {
      const icon = el('i', 'Buzz-avatar Buzz-avatar--icon ' + event.icon);
      icon.setAttribute('aria-hidden', 'true');
      face.appendChild(icon);
    }
    if (profile) {
      const link = el('a', 'Buzz-faceLink');
      link.href = profile;
      link.setAttribute('aria-label', event.user.name);
      link.addEventListener('click', (e) => navigate(e, profile));
      link.appendChild(face);
      card.appendChild(link);
    } else {
      card.appendChild(face);
    }

    const body = el('div', 'Buzz-body');
    if (named) {
      const name = cfg.username && event.user ? event.user.name : trans('someone');
      const nameNode = el(profile ? 'a' : 'strong', 'Buzz-name', name);
      if (profile) {
        nameNode.href = profile;
        nameNode.addEventListener('click', (e) => navigate(e, profile));
      }
      body.appendChild(nameNode);
    }
    const what = named ? text.split(MARK).join('').trim() : text;
    const action = withSubject(el(href ? 'a' : 'span', 'Buzz-action'), what);
    if (href) {
      action.href = href;
      action.addEventListener('click', (e) => navigate(e, href));
    }
    body.appendChild(action);
    card.appendChild(body);

    const close = el('button', 'Buzz-close');
    close.type = 'button';
    close.setAttribute('aria-label', trans('close'));
    close.appendChild(el('i', 'fas fa-times'));
    close.addEventListener('click', () => {
      const item = this.active.find((a) => a.node === card);
      if (item) this.dismiss(item);
    });
    card.appendChild(close);

    return card;
  }

  allowedHere() {
    return this.cfg.pages !== 'home' || app.current.get('routeName') === 'index';
  }

  held() {
    const composer = app.composer;
    return this.cfg.hideComposing && composer && composer.isVisible() && composer.position !== 'minimized';
  }

  next() {
    clearTimeout(this.timer);

    if (!this.queue.length || !this.allowedHere()) {
      this.queue = [];
      return this.hide();
    }

    // Writing a post: wait, and look again in a moment.
    if (this.held()) {
      this.hide();
      this.timer = setTimeout(() => this.next(), 2000);
      return;
    }

    if (this.marquee) return this.scroll();

    const event = this.queue.shift();
    // A burst of one kind becomes one line and "+N more".
    const same = this.queue.filter((e) => e.type === event.type);
    this.queue = this.queue.filter((e) => e.type !== event.type);

    this.current = event;
    this.render([this.card(event, same.length)]);
    this.wait(this.cfg.seconds * 1000);
  }

  wait(ms) {
    this.remaining = ms;
    this.startedAt = Date.now();
    if (!this.paused) this.timer = setTimeout(() => this.advance(), ms);
  }

  advance() {
    this.current = null;
    if (this.queue.length) {
      this.root.classList.remove('is-in');
      this.timer = setTimeout(() => this.next(), reducedMotion() ? 0 : 250);
    } else {
      this.hide();
    }
  }

  pause(paused) {
    if (this.cfg.style === 'toast') {
      if (this.paused === paused || !(this.active || []).length) return;
      this.paused = paused;
      this.active.forEach((item) => {
        if (paused) {
          clearTimeout(item.timer);
          item.remaining = Math.max(1500, item.remaining - (Date.now() - item.startedAt));
        } else {
          this.time(item, item.remaining);
        }
      });
      return;
    }
    if (this.paused === paused || !this.current) return;
    this.paused = paused;
    this.root.classList.toggle('is-paused', paused);
    if (this.marquee) return;
    if (paused) {
      clearTimeout(this.timer);
      this.remaining = Math.max(1500, this.remaining - (Date.now() - this.startedAt));
    } else {
      this.wait(this.remaining);
    }
  }

  close() {
    clearTimeout(this.timer);
    (this.active || []).forEach((item) => clearTimeout(item.timer));
    this.active = [];
    this.queue = [];
    this.current = null;
    this.paused = false;
    this.hide();
  }

  /** Marquee: everything queued scrolls past once, at an even reading speed. */
  scroll() {
    const events = this.queue.splice(0);
    this.current = events[events.length - 1];
    const track = el('div', 'Buzz-track');
    events.forEach((event) => track.appendChild(this.card(event, 0)));
    this.render([track]);

    const distance = this.root.clientWidth + track.scrollWidth;
    track.style.setProperty('--buzz-from', this.root.clientWidth + 'px');
    track.style.setProperty('--buzz-to', -track.scrollWidth + 'px');
    track.style.animationDuration = Math.max(8, distance / 70) + 's';
    // Only now: started before its duration was set, it would end at once.
    track.classList.add('is-running');
    track.addEventListener('animationend', () => {
      this.current = null;
      this.next();
    });
  }

  card(event, more) {
    const cfg = this.cfg;
    const href = hrefFor(event);
    const profile = cfg.profileLink ? profileHref(event.user) : null;
    const card = el('div', 'Buzz-card');

    if (cfg.avatar && event.user) {
      const avatar = event.user.avatar ? el('img', 'Buzz-avatar') : el('span', 'Buzz-avatar', (event.user.name || '?').charAt(0).toUpperCase());
      if (event.user.avatar) {
        avatar.src = event.user.avatar;
        avatar.alt = '';
        avatar.loading = 'lazy';
      } else {
        avatar.style.setProperty('--buzz-avatar', '#' + stringToColor(event.user.name || ''));
      }
      card.appendChild(avatar);
    }

    const icon = el('i', 'Buzz-icon ' + event.icon);
    icon.setAttribute('aria-hidden', 'true');
    card.appendChild(icon);

    const body = el(href ? 'a' : 'span', 'Buzz-text');
    if (href) {
      body.href = href;
      body.addEventListener('click', (e) => navigate(e, href));
    }

    // The text is split where the name goes; the name may be its own link.
    const parts = String(event.text).split(MARK);
    parts.forEach((part, i) => {
      if (part) body.appendChild(document.createTextNode(part.split(SUBJECT).join('')));
      if (i === parts.length - 1) return;
      const name = cfg.username && event.user ? event.user.name : trans('someone');
      if (profile && cfg.username && event.user && href) {
        // No link inside a link: the name is bold text, the profile is reached from the avatar.
        body.appendChild(el('strong', 'Buzz-user', name));
      } else if (profile && cfg.username && event.user) {
        const a = el('a', 'Buzz-user', name);
        a.href = profile;
        a.addEventListener('click', (e) => navigate(e, profile));
        body.appendChild(a);
      } else {
        body.appendChild(el('strong', 'Buzz-user', name));
      }
    });
    card.appendChild(body);

    // The avatar opens the profile when the text opens the item.
    const avatar = card.querySelector('.Buzz-avatar');
    if (avatar && profile) {
      const link = el('a', 'Buzz-avatarLink');
      link.href = profile;
      link.setAttribute('aria-label', event.user.name);
      link.addEventListener('click', (e) => navigate(e, profile));
      card.replaceChild(link, avatar);
      link.appendChild(avatar);
    }

    if (more > 0) card.appendChild(el('span', 'Buzz-more', trans('more', { count: more })));

    return card;
  }

  render(children) {
    const root = this.root;
    root.replaceChildren(...children);

    const close = el('button', 'Buzz-close');
    close.type = 'button';
    close.setAttribute('aria-label', trans('close'));
    close.appendChild(el('i', 'fas fa-times'));
    close.addEventListener('click', () => this.close());
    root.appendChild(close);

    root.hidden = false;
    this.place();
    // Alerts, the composer and chat panels come and go while a card is up.
    clearInterval(this.watch);
    this.watch = setInterval(this.place, 500);
    // Next frame, so the entrance transition runs.
    requestAnimationFrame(() => root.hidden || root.classList.add('is-in'));
  }

  hide() {
    clearInterval(this.watch);
    this.root.classList.remove('is-in');
    this.root.hidden = true;
    this.root.replaceChildren();
  }

  /**
   * Ticker: just under whatever is fixed to the top of the screen (core's
   * header, Bespoke's, a sub-bar under it), found by probing rather than by
   * name. Toast: above whatever is fixed to the bottom on its side.
   */
  place() {
    if (this.root.hidden) return;
    if (this.placing) return;
    this.placing = requestAnimationFrame(() => {
      this.placing = 0;
      if (this.cfg.style === 'ticker') this.root.style.setProperty('--buzz-top', this.topEdge() + 'px');
      else this.root.style.setProperty('--buzz-lift', this.lift() + 'px');
    });
  }

  topEdge() {
    let top = 0;
    const x = Math.round(window.innerWidth / 2);
    for (let i = 0; i < 3; i++) {
      const hit = document.elementsFromPoint(x, top + 1).find((node) => !this.root.contains(node));
      let node = hit;
      let fixed = null;
      while (node && node !== document.body) {
        const pos = getComputedStyle(node).position;
        if (pos === 'fixed' || pos === 'sticky') fixed = node;
        node = node.parentElement;
      }
      const bottom = fixed ? Math.round(fixed.getBoundingClientRect().bottom) : 0;
      if (bottom <= top || bottom > window.innerHeight / 2) break;
      top = bottom;
    }
    return top;
  }

  lift() {
    const box = this.root.getBoundingClientRect();
    let lift = 0;
    document.querySelectorAll(OBSTACLES).forEach((node) => {
      const r = node.getBoundingClientRect();
      if (!r.width || !r.height || r.top < window.innerHeight / 2 || getComputedStyle(node).visibility === 'hidden') return;
      if (r.right <= box.left || r.left >= box.right) return;
      lift = Math.max(lift, window.innerHeight - r.top);
    });
    return lift;
  }
}
