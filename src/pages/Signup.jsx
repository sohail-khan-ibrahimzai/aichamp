import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faEye, faEyeSlash } from "@fortawesome/free-solid-svg-icons";
import "../styles/auth.css";

import { signupModel } from "../models/signupModel";
import { authService } from "../services/authService";

const Signup = () => {
  const navigate = useNavigate();

  const [form, setForm] = useState(signupModel);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);

  const handleChange = (e) => {
    const { name, type, value, checked } = e.target;
    const newValue = type === "checkbox" ? checked : value;

    setForm((prev) => {
      const updated = { ...prev, [name]: newValue };
      if ((name === "password" || name === "confirm") && updated.password && updated.confirm) {
        if (updated.password === updated.confirm) {
          setError("");
        }
      }
      return updated;
    });

    if (name === "terms" && checked) {
      setError("");
    }
  };

  const handleSignup = async (e) => {
    e.preventDefault();

    if (!form.terms) return setError("Please accept the Terms & Conditions");
    if (form.password !== form.confirm) return setError("Passwords do not match");

    setError("");
    setLoading(true);

    const payload = {
      first_name: form.firstName,
      last_name: form.lastName,
      email: form.email,
      password: form.password,
      phone: form.phone,
    };

    const res = await authService.signup(payload);
    setLoading(false);

    if (!res.ok) {
      const { status, data } = res;

      if (status === 429) {
        const retryAfter = data.retry_after || 60;
        return setError(`Too many requests. Try again in ${Math.ceil(retryAfter / 60)} minutes.`);
      }

      if (data.error_code === "EMAIL_EXISTS") {
        return setError("This email address is already registered.");
      }

      return setError(data.message || "Failed to register.");
    }

    navigate("/login");
  };

  return (
    <div id="signupPage" className="page active">
      <div className="auth-container">
        <div className="auth-card">

          <div className="auth-header">
            <h1 className="auth-title">Create an Account</h1>
            <p className="auth-subtitle">Join ScholarCompare and get started</p>
          </div>

          <form onSubmit={handleSignup}>

            <div className="form-group">
              <label className="form-label">First Name</label>
              <input
                name="firstName"
                type="text"
                className="form-control"
                value={form.firstName}
                onChange={handleChange}
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">Last Name</label>
              <input
                name="lastName"
                type="text"
                className="form-control"
                value={form.lastName}
                onChange={handleChange}
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">Institution</label>
              <input
                name="institution"
                type="text"
                className="form-control"
                value={form.institution}
                onChange={handleChange}
              />
            </div>

            <div className="form-group">
              <label className="form-label">Email</label>
              <input
                name="email"
                type="email"
                className="form-control"
                value={form.email}
                onChange={handleChange}
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">Phone Number</label>
              <input
                name="phone"
                type="text"
                className="form-control"
                value={form.phone}
                onChange={handleChange}
              />
            </div>

            <div className="form-group">
              <label className="form-label">Password</label>
              <div className="password-input-group">
                <input
                  name="password"
                  type={showPassword ? "text" : "password"}
                  className="form-control"
                  value={form.password}
                  onChange={handleChange}
                  required
                />
                <button
                  type="button"
                  className="password-toggle-btn"
                  onClick={() => setShowPassword(!showPassword)}
                >
                  <FontAwesomeIcon icon={showPassword ? faEyeSlash : faEye} />
                </button>
              </div>
            </div>

            <div className="form-group">
              <label className="form-label">Confirm Password</label>
              <div className="password-input-group">
                <input
                  name="confirm"
                  type={showConfirm ? "text" : "password"}
                  className="form-control"
                  value={form.confirm}
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
              {error === "Passwords do not match" && <p className="auth-error">{error}</p>}
            </div>

            <div className="form-check">
              <input
                name="terms"
                type="checkbox"
                className="form-check-input"
                checked={form.terms}
                onChange={handleChange}
              />
              <label className="form-check-label">
                I accept the terms and conditions
              </label>
            </div>

            {error && error !== "Passwords do not match" && <p className="auth-error">{error}</p>}

            <button type="submit" className="auth-btn" disabled={loading}>
              {loading ? "Signing up..." : "Create Account"}
            </button>
          </form>

          <div className="auth-footer">
            <p>
              Already have an account?{" "}
              <Link to="/login" className="auth-link">
                Sign in
              </Link>
            </p>
          </div>

        </div>
      </div>
    </div>
  );
};

export default Signup;
