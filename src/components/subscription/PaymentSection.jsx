import { useState, useMemo } from "react";
import "../../styles/PaymentSection.css";

const DISCOUNT = 0.15;

const PaymentSection = ({ plan, onBack }) => {
  const [billing, setBilling] = useState("monthly");
  const [loading, setLoading] = useState(false);

  const priceInfo = useMemo(() => {
    if (billing === "monthly") {
      return { label: "month", total: plan.price };
    }

    const yearly = plan.price * 12;
    return {
      label: "year",
      total: (yearly - yearly * DISCOUNT).toFixed(2),
    };
  }, [billing, plan.price]);

  const handleSubmit = (e) => {
    e.preventDefault();
    setLoading(true);

    setTimeout(() => {
      setLoading(false);
      alert(`Subscribed to ${plan.name} — $${priceInfo.total}/${priceInfo.label}`);
    }, 1500);
  };

  return (
  <div className="payment-section payment-form-wrapper active">
    <div className="payment-card">
      <div className="payment-header">
        <h2 className="payment-title">Complete Your Subscription</h2>
        <div className="selected-plan-info">
          <span className="selected-plan-name">{plan.name}</span>
          <span className="selected-plan-price">
            ${priceInfo.total}/{priceInfo.label}
          </span>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <div className="payment-form">
          <div className="form-group">
            <label className="form-label">Full Name</label>
            <input className="form-control" placeholder="Dr. Jane Smith" required />
          </div>

          <div className="form-group">
            <label className="form-label">Email Address</label>
            <input type="email" className="form-control" placeholder="your.email@university.edu" required />
          </div>

          <div className="form-group full-width">
            <label className="form-label">Card Information</label>
            <div className="card-element-container">
              <div className="card-placeholder">Card number • MM/YY • CVC</div>
            </div>
          </div>

          <div className="form-group">
            <label className="form-label">Coupon Code (Optional)</label>
            <input className="form-control" placeholder="SUMMER2024" />
          </div>

          <div className="form-group">
            <label className="form-label">Billing Cycle</label>
            <select
              className="form-control"
              value={billing}
              onChange={(e) => setBilling(e.target.value)}
            >
              <option value="monthly">Monthly Billing</option>
              <option value="yearly">Yearly Billing (Save 15%)</option>
            </select>
          </div>
        </div>

        <div className="form-group full-width">
          <div className="form-check">
            <input type="checkbox" required />
            <label className="form-check-label">
              I agree to the <a href="#">Terms of Service</a>
            </label>
          </div>
        </div>

        <div className="payment-actions">
          <button type="button" className="back-to-plans" onClick={onBack}>
            ← Back to Plans
          </button>
          <button className="submit-payment" disabled={loading}>
            {loading ? "Processing..." : "Subscribe Now"}
          </button>
        </div>
      </form>
     </div>
  </div>
  );
};

export default PaymentSection;
