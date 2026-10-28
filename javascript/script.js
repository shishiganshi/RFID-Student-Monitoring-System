function loginUser(event) {
    event.preventDefault();
    const user = document.getElementById("username").value;
    const pass = document.getElementById("password").value;
  
    // Dummy login for demo
    if (user === "admin" && pass === "admin") {
      window.location.href = "dashboard.html";
    } else {
      alert("Invalid credentials. Try admin/admin");
    }
  }
  