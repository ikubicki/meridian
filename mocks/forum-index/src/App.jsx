import { useState } from 'react';
import { forums, stats, onlineUsers, birthdays, FORUM_TYPE_CAT, FORUM_TYPE_POST, FORUM_TYPE_LINK } from './data.js';
import ForumList from './components/ForumList.jsx';
import StatBlocks from './components/StatBlocks.jsx';

export default function App() {
  return (
    <div id="wrap" className="wrap">
      <Header />
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
                src="/src/assets/site_logo.svg"
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
                  🔍
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

/* ── Hamburger menu ─────────────────────────────────────── */
function HamburgerMenu() {
  const [open, setOpen] = useState(false);

  return (
    <div
      className="header-hamburger dropdown-container"
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <button
        className="hamburger-btn"
        aria-label="Menu"
        aria-expanded={open}
      >
        ☰
      </button>
      {open && (
        <div className="dropdown dropdown-right">
          <ul className="dropdown-contents" role="menu">
            <li><a href="#" role="menuitem">❓ FAQ</a></li>
            <li className="separator" />
            <li><a href="#" role="menuitem">Unanswered topics</a></li>
            <li><a href="#" role="menuitem">Active topics</a></li>
            <li><a href="#" role="menuitem">The team</a></li>
            <li><a href="#" role="menuitem">Members</a></li>
            <li className="separator" />
            <li><a href="#" role="menuitem">✏ Register</a></li>
            <li><a href="#" role="menuitem">⏻ Login</a></li>
          </ul>
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
        <span className="footer-right">Style by Irekk</span>
      </div>
    </footer>
  );
}
