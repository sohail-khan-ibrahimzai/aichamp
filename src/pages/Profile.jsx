import { useEffect, useState } from "react";
import { authService } from "../services/authService";
import "../styles/auth.css";

const Profile = () => {
  const [form, setForm] = useState({
    firstName: "",
    lastName: "",
    phone: "",
    avatarUrl: "",
  });
  const [loading, setLoading] = useState(false);
  const [fetching, setFetching] = useState(true);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

useEffect(() => {
  const fetchProfile = async () => {
    setFetching(true);
    setError("");
    const res = await authService.getProfile();
    setFetching(false);

    if (!res.ok) {
      setError(res.data.message || "Failed to fetch profile.");
      return;
    }

    const { first_name, last_name, phone, avatar_url, email } = res.data.data.user;
    setForm({
      firstName: first_name || "",
      lastName: last_name || "",
      phone: phone || "",
      avatarUrl: avatar_url || "",
      email: email || "",
    });
  };

  fetchProfile();
}, []);



  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setSuccess("");
    setLoading(true);

    const payload = {
      first_name: form.firstName,
      last_name: form.lastName,
      phone: form.phone,
      avatar_url: form.avatarUrl,
    };

    const res = await authService.updateProfile(payload);
    setLoading(false);

    if (!res.ok) {
      setError(res.data.message || "Failed to update profile.");
      return;
    }

    setSuccess("Profile updated successfully.");
  };

  if (fetching) return <p style={{ padding: "20px" }}>Loading profile...</p>;

  return (
    <div className="page active">
      <div className="auth-container">
        <div className="auth-card">
          <div className="auth-header">
            <h1 className="auth-title">My Profile</h1>
            <p className="auth-subtitle">Update your personal information</p>
          </div>

          <form onSubmit={handleSubmit} className="auth-form">
            <div className="form-group">
            <label className="form-label">Email Address</label>
            <input
              type="email"
              className="form-control"
              value={form.email}
              disabled
            />
          </div>

            <div className="form-group">
              <label className="form-label">First Name</label>
              <input
                type="text"
                name="firstName"
                className="form-control"
                value={form.firstName}
                onChange={handleChange}
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">Last Name</label>
              <input
                type="text"
                name="lastName"
                className="form-control"
                value={form.lastName}
                onChange={handleChange}
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">Phone</label>
              <input
                type="text"
                name="phone"
                className="form-control"
                value={form.phone}
                onChange={handleChange}
              />
            </div>

            <div className="form-group">
              <label className="form-label">Avatar URL</label>
              <input
                type="text"
                name="avatarUrl"
                className="form-control"
                value={form.avatarUrl}
                onChange={handleChange}
              />
            </div>

            {error && <p className="auth-error">{error}</p>}
            {success && <p className="auth-success">{success}</p>}

            <button type="submit" className="auth-btn" disabled={loading}>
              {loading ? "Updating..." : "Update Profile"}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
};

export default Profile;
