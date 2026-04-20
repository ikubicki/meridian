import { useState, useEffect, useRef } from 'react';
import { forums, stats, onlineUsers, birthdays, FORUM_TYPE_CAT, FORUM_TYPE_POST, FORUM_TYPE_LINK } from './data.js';
import ForumList from './components/ForumList.jsx';
import StatBlocks from './components/StatBlocks.jsx';

export default function App() {
  return (
    <div id="wrap" className="wrap">
      <Header />
      <StickyHeader />
      <main id="page-body">
        <div className="page-body-inner">
          <ActionBar />
          <ForumList forums={forums} />
          <StatBlocks stats={stats} onlineUsers={onlineUsers} birthdays={birthdays} />
        </div>
      </main>
      <Footer />
    </div>
  );
}

/* ── Header ─────────────────────────────────────────────── */
function Header() {
  return (
    <div id="page-header">
      <div className="headerbar" role="banner">
        <div className="inner headerbar-inner">
          {/* Left: logo */}
          <div className="header-logo">
            <a href="#" title="Board index">
              <img
                className="site-logo"
                src="/site_logo.svg"
                alt="phpBB"
              />
            </a>
          </div>

          {/* Center: search */}
          <div className="header-search" role="search">
            <form onSubmit={(e) => e.preventDefault()}>
              <fieldset>
                <input
                  name="keywords"
                  type="search"
                  maxLength={128}
                  className="inputbox search"
                  placeholder="Search…"
                />
                <button className="button button-search" type="submit" title="Search">
                  <span className="material-symbols-outlined">search</span>
                </button>
              </fieldset>
            </form>
          </div>

          {/* Right: hamburger menu */}
          <HamburgerMenu />
        </div>
      </div>
    </div>
  );
}

/* ── Hamburger menu (CSS-only hover) ────────────────────── */
function HamburgerMenu() {
  return (
    <div className="header-hamburger">
      <button className="hamburger-btn" aria-label="Menu"><span className="material-symbols-outlined">menu</span></button>
      <div className="dropdown dropdown-right">
        <ul className="dropdown-contents" role="menu">
          <li><a href="#" role="menuitem"><span className="material-symbols-outlined menu-icon">help</span> FAQ</a></li>
          <li className="separator" />
          <li><a href="#" role="menuitem"><span className="material-symbols-outlined menu-icon">mark_chat_unread</span> Unanswered topics</a></li>
          <li><a href="#" role="menuitem"><span className="material-symbols-outlined menu-icon">local_fire_department</span> Active topics</a></li>
          <li><a href="#" role="menuitem"><span className="material-symbols-outlined menu-icon">groups</span> The team</a></li>
          <li><a href="#" role="menuitem"><span className="material-symbols-outlined menu-icon">people</span> Members</a></li>
          <li className="separator" />
          <li><a href="#" role="menuitem"><span className="material-symbols-outlined menu-icon">person_add</span> Register</a></li>
          <li><a href="#" role="menuitem"><span className="material-symbols-outlined menu-icon">login</span> Login</a></li>
        </ul>
      </div>
    </div>
  );
}

/* ── Sticky Header (appears after 100px scroll) ─────────── */
function StickyHeader() {
  const [visible, setVisible] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);

  useEffect(() => {
    const onScroll = () => setVisible(window.scrollY > 100);
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  return (
    <div className={`sticky-header${visible ? ' sticky-visible' : ''}`}>
      <div className="sticky-inner">
        <div className="sticky-left">
          <a href="#" className="sticky-logo" title="Board index">
            <img src="/favicon.ico" alt="phpBB" className="sticky-ico" />
            <img src="./site_logo.svg" alt="phpBB" className="sticky-svg" />
          </a>
          <ul className="breadcrumbs">
            <li className="crumb"><a href="#">Board index</a></li>
          </ul>
        </div>

        <div className="sticky-right">
          <button
            className="sticky-search-btn"
            onClick={() => setSearchOpen((v) => !v)}
            title="Search"
          >
            <span className="material-symbols-outlined">search</span>
          </button>
          <HamburgerMenu />
        </div>
      </div>

      {searchOpen && (
        <div className="sticky-search-bar">
          <form onSubmit={(e) => e.preventDefault()}>
            <input
              name="keywords"
              type="search"
              maxLength={128}
              className="inputbox search"
              placeholder="Search…"
              autoFocus
            />
          </form>
        </div>
      )}
    </div>
  );
}

/* ── Action bar (breadcrumb + mark forums read) ────────── */
function ActionBar() {
  return (
    <div className="action-bar compact">
      <ul className="breadcrumbs">
        <li className="crumb"><a href="#">Board index</a></li>
      </ul>
      <a href="#" className="mark-read">Mark forums read</a>
    </div>
  );
}

/* ── Footer ─────────────────────────────────────────────── */
function Footer() {
  return (
    <footer id="page-footer">
      <div className="footer-info">
        <span>Powered by <a href="#">phpBB</a>® Forum Software © phpBB Limited</span>
        <span className="footer-right">Skysilver style by Irekk</span>
      </div>
    </footer>
  );
}
