import { useContext } from "react";
import { useNavigate } from "react-router-dom";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
  faMoon,
  faGraduationCap,
  faUserGraduate,
  faUserCircle,
  faBrain,
  faCog,
  faBookmark,
  faHistory,
  faQuestionCircle,
  faSignOutAlt,
  faKey
} from "@fortawesome/free-solid-svg-icons";

import "../styles/Header.css";
import { AuthContext } from "../guards/context/AuthContext";
import { faCrown } from "@fortawesome/free-solid-svg-icons";

const Header = () => {
  const navigate = useNavigate();
  const { logout } = useContext(AuthContext);

  return (
    <header className="app-header">
      <div className="header-left">
        <FontAwesomeIcon icon={faGraduationCap} className="header-logo-icon" />
        <div className="header-logo-text">
          <div className="logo-text">ScholarCompare</div>
          <div className="logo-subtitle">AI Model Comparator for Academia</div>
        </div>
      </div>

      <div className="header-right">
        <button className="theme-toggle" aria-label="Toggle theme">
          <FontAwesomeIcon icon={faMoon} />
        </button>

        <div className="user-profile">
          <FontAwesomeIcon icon={faUserGraduate} className="profile-icon" />
          <div className="profile-menu">
            <ul>
              <li onClick={() => navigate("/profile")}>
                <FontAwesomeIcon icon={faUserCircle} /> My Profile
              </li>
              {/* <li><a href="#"><FontAwesomeIcon icon={faUserCircle} /> My Profile</a></li> */}
              {/* <li><a href="#"><FontAwesomeIcon icon={faBrain} /> AI Comparator</a></li> */}
              {/* <li><a href="#"><FontAwesomeIcon icon={faCog} /> Settings</a></li> */}
              {/* <li><a href="#"><FontAwesomeIcon icon={faBookmark} /> Saved Comparisons</a></li> */}
              {/* <li><a href="#"><FontAwesomeIcon icon={faHistory} /> History</a></li> */}
              {/* <li><a href="#"><FontAwesomeIcon icon={faQuestionCircle} /> Help & Support</a></li> */}
              <li onClick={() => navigate("/change-password")}>
                <FontAwesomeIcon icon={faKey} /> Change Password
              </li>
              <li onClick={() => navigate("/upgrade")}>
                <FontAwesomeIcon icon={faCrown} /> Upgrade Plan
              </li>
              <li><a href="#" onClick={logout}><FontAwesomeIcon icon={faSignOutAlt} /> Logout</a></li>
            </ul>
          </div>
        </div>
      </div>
    </header>
  );
};

export default Header;
