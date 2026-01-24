import { useState } from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faEye, faEyeSlash } from "@fortawesome/free-solid-svg-icons";
import { toast } from "react-toastify";
import "../styles/auth.css";
import { useNavigate } from "react-router-dom";
import { authService } from "../services/authService";

const ChangePassword = () => {
  const navigate = useNavigate();

  const [form, setForm] = useState({
    currentPassword: "",
    newPassword: "",
    confirmPassword: "",
  });

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm((prev) => {
      const updated = { ...prev, [name]: value };
      if ((name === "newPassword" || name === "confirmPassword") && updated.newPassword && updated.confirmPassword) {
        if (updated.newPassword === updated.confirmPassword) {
          setError("");
        }
      }
      return updated;
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");

    if (form.newPassword !== form.confirmPassword) {
      return setError("New password and confirm password do not match.");
    }

    setLoading(true);

    const payload = {
      current_password: form.currentPassword,
      new_password: form.newPassword,
      confirm_password: form.confirmPassword,
    };

    const res = await authService.changePassword(payload);
    setLoading(false);

    if (!res.ok) {
      return setError(res.data.message || "Failed to update password.");
    }

    toast.success("Password updated successfully.");
    setForm({ currentPassword: "", newPassword: "", confirmPassword: "" });
  };

  return (
    <div className="page active">
      <div className="auth-container">
        <div className="auth-card">
          <div className="auth-header">
            <h1 className="auth-title">Update Password</h1>
            <p className="auth-subtitle">Change your account password securely</p>
          </div>

          <form onSubmit={handleSubmit} className="auth-form">
            <div className="form-group">
              <label className="form-label">Current Password</label>
              <div className="password-input-group">
                <input
                  type={showCurrent ? "text" : "password"}
                  name="currentPassword"
                  className="form-control"
                  value={form.currentPassword}
                  onChange={handleChange}
                  required
                />
                <button
                  type="button"
                  className="password-toggle-btn"
                  onClick={() => setShowCurrent(!showCurrent)}
                >
                  <FontAwesomeIcon icon={showCurrent ? faEyeSlash : faEye} />
                </button>
              </div>
            </div>

            <div className="form-group">
              <label className="form-label">New Password</label>
              <div className="password-input-group">
                <input
                  type={showNew ? "text" : "password"}
                  name="newPassword"
                  className="form-control"
                  value={form.newPassword}
                  onChange={handleChange}
                  required
                />
                <button
                  type="button"
                  className="password-toggle-btn"
                  onClick={() => setShowNew(!showNew)}
                >
                  <FontAwesomeIcon icon={showNew ? faEyeSlash : faEye} />
                </button>
              </div>
            </div>

            <div className="form-group">
              <label className="form-label">Confirm New Password</label>
              <div className="password-input-group">
                <input
                  type={showConfirm ? "text" : "password"}
                  name="confirmPassword"
                  className="form-control"
                  value={form.confirmPassword}
                  onChange={handleChange}
                  required
                />
                <button
                  type="button"
                  className="password-toggle-btn"
                  onClick={() => setShowConfirm(!showConfirm)}
                >
                  <FontAwesomeIcon icon={showConfirm ? faEyeSlash : faEye} />
                </button>
              </div>
              {error && <p className="auth-error">{error}</p>}
            </div>

            <button type="submit" className="auth-btn" disabled={loading}>
              {loading ? "Updating..." : "Update Password"}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
};

export default ChangePassword;
