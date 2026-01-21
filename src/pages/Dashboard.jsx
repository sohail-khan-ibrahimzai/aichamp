import React, { useState, useEffect, useRef } from "react";
import "../styles/Dashboard.css";
import { chatService } from "../services/chat/ChatService";
import { sessionService } from "../services/chat/session/SessionService";

import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import { Prism as SyntaxHighlighter } from "react-syntax-highlighter";
import { duotoneDark } from "react-syntax-highlighter/dist/esm/styles/prism";
import { useContext } from "react";
import { AuthContext } from "../guards/context/AuthContext";
const Dashboard = ({
  sessionData,
  sessionMessages,
  sessionModels,
  onSessionChange,
  onSessionCreated,
}) => {
  const [prompt, setPrompt] = useState("");
  const [error, setError] = useState("");
  const [models, setModels] = useState([]);
  const [messages, setMessages] = useState({});
  const [loadingModels, setLoadingModels] = useState({});

  const sessionId = sessionData?.id || null;
  const bottomRefs = useRef({});
  const { token } = useContext(AuthContext);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    const load = async () => {
      if (sessionData && sessionModels?.length > 0) {
        const mappedModels = sessionModels.map((m) => ({
          id: m.model_id,
          name: m.model_name_full,
          visible: Number(m.is_visible),
        }));

        const msgMap = {};
        mappedModels.forEach((m) => {
          msgMap[m.id] = [];
          bottomRefs.current[m.id] = bottomRefs.current[m.id] || React.createRef();
        });

        // Group messages by prompt_id for responses
        const promptMap = {};
        sessionMessages?.forEach((msg) => {
           if (msg.type === "prompt") {
             promptMap[msg.id] = msg;
           }
         });

        sessionMessages?.forEach((msg) => {
           if (msg.type === "response" && msgMap[msg.model_id]) {
             // Add the corresponding prompt first, if not already added
             const prompt = promptMap[msg.prompt_id];
             if (prompt && !msgMap[msg.model_id].some(m => m.type === "prompt" && m.content === prompt.content)) {
               msgMap[msg.model_id].push({ type: "prompt", content: prompt.content });
             }
             // Then add the response
             msgMap[msg.model_id].push({
               type: "response",
               content: msg.content,
             });
           }
         });

        setModels(mappedModels);
        setMessages(msgMap);
        return;
      }

      if (!sessionData) {
        const res = await chatService.getModels();
        if (!res.ok) return;

        const mappedModels = res.data.data.map((m) => ({
          id: m.id,
          name: m.name,
          visible: 1,
        }));

        const msgMap = {};
        mappedModels.forEach((m) => {
          msgMap[m.id] = [];
          bottomRefs.current[m.id] = React.createRef();
        });

        setModels(mappedModels);
        setMessages(msgMap);
      }
    };

    load();
  }, [sessionData, sessionModels, sessionMessages]);

  useEffect(() => {
    const restoreSession = async () => {
      if (sessionData) return;

      const storedSessionId = localStorage.getItem("currentSessionId");
      if (!storedSessionId) return;

      try {
        const sessionRes = await sessionService.getSessionById(storedSessionId);
        const modelRes = await sessionService.getSessionModels(storedSessionId);
        const msgRes = await sessionService.getSessionMessages(storedSessionId);

        if (!sessionRes.ok || !modelRes.ok) return;

        onSessionChange(
          sessionRes.data.data.session,
          msgRes?.data?.data?.messages || [],
          modelRes.data.data.models
        );
      } catch (err) {
        console.error("Failed to restore session", err);
      }
    };

    restoreSession();
  }, []);

  useEffect(() => {
    models.forEach((model) => {
      bottomRefs.current[model.id]?.current?.scrollIntoView({
        behavior: "smooth",
      });
    });
  }, [messages, loadingModels, models]);

  const handleToggle = async (modelId, newState) => {
    try {
      const res = await sessionService.updateModelVisibility(
        sessionId,
        modelId,
        { is_visible: newState }
      );
      if (!res.ok) return;

      setModels((prev) =>
        prev.map((m) =>
          m.id === modelId ? { ...m, visible: newState } : m
        )
      );
    } catch (err) {
      console.error(err);
    }
  };

  const generateTitle = (text) =>
    text.trim().split(/\s+/).slice(0, 3).join(" ");

  const isSending = Object.values(loadingModels).some(Boolean);

  const handleSubmit = async () => {
    if (!prompt.trim() || isSending) return;
    setError("");

    try {
      let activeSessionId = sessionData?.id || null;

      if (!activeSessionId) {
        const createRes = await sessionService.createSession(
          generateTitle(prompt)
        );
        if (!createRes.ok) throw new Error("Create failed");

        const newSession = createRes.data.data.session;
        activeSessionId = newSession.id;

        localStorage.setItem("currentSessionId", activeSessionId);

        const modelsRes = await chatService.getModels();
        if (modelsRes.ok) {
          for (const m of modelsRes.data.data) {
            await sessionService.assignModelToSession(activeSessionId, m.id);
            await sessionService.updateModelVisibility(activeSessionId, m.id, {
              is_visible: 1,
            });
          }
        }

        onSessionChange(newSession, [], []);
        onSessionCreated(newSession);
      }

      await sessionService.activateSession(activeSessionId);

      const loaders = {};
      models.forEach((m) => {
        if (m.visible === 1) loaders[m.id] = true;
      });
      setLoadingModels(loaders);

      const newMessages = { ...messages };
      Object.keys(newMessages).forEach((id) => {
        newMessages[id].push({ type: "prompt", content: prompt });
      });
      setMessages({ ...newMessages });

      for (const model of models) {
        if (model.visible !== 1) continue;

        const res = await chatService.sendPromptToModel(
          activeSessionId,
          model.id,
          prompt
        );

        if (res.ok) {
          newMessages[model.id].push({
            type: "response",
            content: res.data?.data?.response?.content || "",
          });
        }

        setLoadingModels((prev) => ({ ...prev, [model.id]: false }));
        setMessages({ ...newMessages });
      }

      setPrompt("");
    } catch (err) {
      console.error(err);
      setError("Error sending prompt");
    }
  };

  return (
    <main className="dashboard">
      <div className="models-row">
        {models.map((model) => (
          <div className="model-card" key={model.id}>
            <div className="model-card-header">
              <span className="model-title">{model.name}</span>

              <label
                className={`switch ${
                  (!sessionId || models.length === 1)
                    ? "switch-disabled"
                    : ""
                }`}
              >
                <input
                  type="checkbox"
                  checked={model.visible === 1}
                  onChange={(e) =>
                    handleToggle(model.id, e.target.checked ? 1 : 0)
                  }
                  disabled={!sessionId || models.length === 1}
                />
                <span className="slider round"></span>
              </label>
            </div>

            <div className="model-card-content">
              <div className="chat-window">
                {(messages[model.id] || []).map((msg, idx) => (
                  <div
                    key={idx}
                    className={`chat-bubble ${
                      msg.type === "prompt" ? "msg-user" : "msg-ai"
                    }`}
                  >
                    <ReactMarkdown
                      remarkPlugins={[remarkGfm]}
                      components={{
                        code({ inline, className, children }) {
                          const match =
                            /language-(\w+)/.exec(className || "");
                          return !inline && match ? (
                            <SyntaxHighlighter
                              style={duotoneDark}
                              language={match[1]}
                            >
                              {String(children)}
                            </SyntaxHighlighter>
                          ) : (
                            <code className="inline-code">{children}</code>
                          );
                        },
                      }}
                    >
                      {msg.content}
                    </ReactMarkdown>
                  </div>
                ))}

                {loadingModels[model.id] && (
                  <div className="chat-bubble msg-ai typing">
                    <div className="dot-loader">
                      <span></span>
                      <span></span>
                      <span></span>
                    </div>
                  </div>
                )}

                <div ref={bottomRefs.current[model.id]} />
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="prompt-box">
        <div className="prompt-inner">
          <textarea
            className="prompt-input"
            placeholder="Ask anything…"
            value={prompt}
            disabled={isSending}
            rows={1}
            onChange={(e) => setPrompt(e.target.value)}
            onKeyDown={(e) => {
              if (isSending) {
                e.preventDefault();
                return;
              }
              if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                handleSubmit();
              }
            }}
          />

          <button
            className="submit-btn"
            onClick={handleSubmit}
            disabled={!prompt.trim() || isSending}
          >
            ➤
          </button>
        </div>
      </div>
    </main>
  );
};

export default Dashboard;
