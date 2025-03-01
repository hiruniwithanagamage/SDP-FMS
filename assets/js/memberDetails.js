let currentMemberId = null;

function viewMemberDetails(id) {
    console.log('Viewing member with ID:', id);
    currentMemberId = id;
    document.getElementById('viewDetailsModal').style.display = 'block';
    document.getElementById('memberDetailsContent').innerHTML = '<div class="loading">Loading details...</div>';
    
    // Fetch member details via AJAX
    fetch('getMemberDetails.php?id=' + id)
        .then(response => {
            // First check if the response is OK
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                document.getElementById('memberDetailsContent').innerHTML = '<div class="error">Error: ' + data.error + '</div>';
                return;
            }
            
            // Format member details in HTML
            let html = `
                <div class="member-details">
                    <div class="member-photo">
                        ${data.Image ? `<img src="../uploads/${data.Image}" alt="Profile Photo" style="max-width: 200px; max-height: 200px;">` : '<div class="no-photo">No photo available</div>'}
                    </div>
                    <div class="member-info">
                        <div class="detail-row">
                            <div class="detail-label">Member ID:</div>
                            <div class="detail-value">${data.MemberID}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Name:</div>
                            <div class="detail-value">${data.Name}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">NIC:</div>
                            <div class="detail-value">${data.NIC}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Date of Birth:</div>
                            <div class="detail-value">${data.DoB}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Address:</div>
                            <div class="detail-value">${data.Address}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Contact Number:</div>
                            <div class="detail-value">${data.Mobile_Number || 'Not provided'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Family Members:</div>
                            <div class="detail-value">${data.No_of_Family_Members}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Other Members:</div>
                            <div class="detail-value">${data.Other_Members}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value">
                                <span class="status-badge status-${data.Status === 'TRUE' ? 'active' : 'pending'}">
                                    ${data.Status === 'TRUE' ? 'Full Member' : 'Pending'}
                                </span>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Joined Date:</div>
                            <div class="detail-value">${new Date(data.Joined_Date).toLocaleDateString()}</div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('memberDetailsContent').innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('memberDetailsContent').innerHTML = 
                '<div class="error">Error fetching data: ' + error.message + '</div>';
        });
}

function closeViewModal() {
    document.getElementById('viewDetailsModal').style.display = 'none';
    currentMemberId = null;
}

function openEditModalFromView() {
    if (!currentMemberId) return;
    
    // Close the view modal
    closeViewModal();
    
    // Fetch the member data and open the edit modal
    fetch('getMemberDetails.php?id=' + currentMemberId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            
            openEditModal(
                data.MemberID,
                data.Name,
                data.NIC,
                data.DoB,
                data.Address,
                data.Mobile_Number,
                data.No_of_Family_Members,
                data.Other_Members,
                data.Status,
                data.Image
            );
        })
        .catch(error => {
            alert('Error fetching data. Please try again.');
            console.error('Error:', error);
        });
}

// Add to your memberDetails.js file
function openEditModal(id, name, nic, dob, address, mobile, familyMembers, otherMembers, status, image) {
    document.getElementById('editModal').style.display = 'block';
    document.getElementById('edit_member_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_nic').value = nic;
    document.getElementById('edit_dob').value = dob;
    document.getElementById('edit_address').value = address;
    document.getElementById('edit_mobile').value = mobile || '';
    document.getElementById('edit_family_members').value = familyMembers;
    document.getElementById('edit_other_members').value = otherMembers;
    document.getElementById('edit_status').value = status;
    
    // Handle the profile photo
    const photoContainer = document.getElementById('current_photo_container');
    const photoDiv = document.getElementById('current_photo');
    
    if (image) {
        photoContainer.style.display = 'block';
        photoDiv.innerHTML = `<img src="../uploads/${image}" alt="Current Photo" style="max-width: 150px; max-height: 150px;">`;
    } else {
        photoContainer.style.display = 'none';
        photoDiv.innerHTML = '';
    }
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

function openDeleteModal(id) {
    document.getElementById('deleteModal').style.display = 'block';
    document.getElementById('delete_member_id').value = id;
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Add event listeners
function initMemberDetailsEvents() {
    // Close modals when clicking outside
    window.onclick = function(event) {
        const editModal = document.getElementById('editModal');
        const deleteModal = document.getElementById('deleteModal');
        const viewModal = document.getElementById('viewDetailsModal');
        
        if (event.target === editModal) {
            closeModal();
        }
        if (event.target === deleteModal) {
            closeDeleteModal();
        }
        if (event.target === viewModal) {
            closeViewModal();
        }
    }
    
    // Prevent click events inside the modals from bubbling up
    document.querySelectorAll('.modal-content, .delete-modal-content').forEach(content => {
        content.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
    
    // Stop propagation on action buttons to prevent row click
    document.querySelectorAll('.action-buttons button').forEach(button => {
        button.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
}

document.addEventListener('DOMContentLoaded', initMemberDetailsEvents);