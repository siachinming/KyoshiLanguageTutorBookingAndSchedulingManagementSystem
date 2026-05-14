<!-- SEARCH MODAL -->
<div id="searchModal" style="display:none;position:fixed;inset:0;background:rgba(52,38,53,.5);backdrop-filter:blur(6px);z-index:200;padding:60px 20px;overflow-y:auto;">
  <div style="max-width:700px;margin:0 auto;background:white;border-radius:28px;padding:28px;box-shadow:0 30px 60px rgba(201,79,134,.2);position:relative;">

    <!-- Search bar row -->
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
      <div style="position:relative;flex:1;">
        <i class="bi bi-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F;"></i>
        <input type="text" id="modalTutorSearchInput" placeholder="Search by language..."
          style="width:100%;padding:14px 14px 14px 40px;border:1px solid rgba(46,42,59,.12);border-radius:999px;outline:none;font-size:15px;box-sizing:border-box;"
          oninput="filterTutors()">
      </div>
      <div style="position:relative;flex:0 0 auto;">
        <button onclick="toggleSearchFilters()" id="filterBtn"
          style="width:44px;height:44px;border-radius:14px;border:1px solid rgba(242,138,178,.3);background:white;cursor:pointer;font-size:18px;color:#E75A9B;position:relative;" title="Filters">
          <i class="bi bi-sliders"></i>
          <span id="filterDot" style="display:none;position:absolute;top:8px;right:8px;width:8px;height:8px;border-radius:50%;background:#E75A9B;"></span>
        </button>

        <!-- Filter dropdown panel -->
        <div id="searchFilters" style="display:none;position:absolute;top:52px;right:0;width:380px;max-height:70vh;overflow-y:auto;background:white;border-radius:20px;padding:20px;box-shadow:0 20px 50px rgba(52,38,53,.2);z-index:400;border:1px solid rgba(242,138,178,.22);">
          
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <strong style="font-size:15px;color:#342635;">Filter Tutors</strong>
            <button onclick="toggleSearchFilters()" style="background:none;border:none;font-size:18px;cursor:pointer;color:#7A5570;">✕</button>
          </div>

          <!-- Price Range -->
          <div style="margin-bottom:18px;">
            <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;">
              <i class="bi bi-cash-coin" style="color:#E75A9B;margin-right:5px;"></i> Price Range (per hour)
            </p>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="flex:1;position:relative;">
                <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:13px;color:#9080a0;font-weight:700;">RM</span>
                <input type="number" id="priceFrom" min="0" max="100" value="0" placeholder="0"
                  oninput="filterTutors()"
                  style="width:100%;padding:10px 10px 10px 34px;border:1px solid rgba(46,42,59,.12);border-radius:12px;outline:none;font-size:14px;font-weight:700;color:#342635;">
              </div>
              <span style="color:#9080a0;font-size:13px;flex-shrink:0;">to</span>
              <div style="flex:1;position:relative;">
                <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:13px;color:#9080a0;font-weight:700;">RM</span>
                <input type="number" id="priceTo" min="0" max="100" value="100" placeholder="100"
                  oninput="filterTutors()"
                  style="width:100%;padding:10px 10px 10px 34px;border:1px solid rgba(46,42,59,.12);border-radius:12px;outline:none;font-size:14px;font-weight:700;color:#342635;">
              </div>
            </div>
          </div>

          <hr style="border:none;border-top:1px solid rgba(242,138,178,.18);margin:0 0 14px;">

          <!-- Language -->
          <div style="margin-bottom:18px;">
            <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;">
              <i class="bi bi-globe2" style="color:#E75A9B;margin-right:5px;"></i> Language
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;" id="langFilterChips">
              <button type="button" class="modal-filter-chip" onclick="toggleModalChip(this,'lang');filterTutors();" data-value="japanese" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Japanese</button>
              <button type="button" class="modal-filter-chip" onclick="toggleModalChip(this,'lang');filterTutors();" data-value="english" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">English</button>
              <button type="button" class="modal-filter-chip" onclick="toggleModalChip(this,'lang');filterTutors();" data-value="mandarin" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Mandarin</button>
              <button type="button" class="modal-filter-chip" onclick="toggleModalChip(this,'lang');filterTutors();" data-value="korean" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Korean</button>
              <button type="button" class="modal-filter-chip" onclick="toggleModalChip(this,'lang');filterTutors();" data-value="malay" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Malay</button>
            </div>
          </div>

          <hr style="border:none;border-top:1px solid rgba(242,138,178,.18);margin:0 0 14px;">

          <!-- Teaching Mode -->
          <div style="margin-bottom:18px;">
            <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;">
              <i class="bi bi-laptop" style="color:#E75A9B;margin-right:5px;"></i> Teaching Mode
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
              <button type="button" class="modal-filter-chip" onclick="toggleModalChip(this,'mode');filterTutors();" data-value="online" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">💻 Online</button>
              <button type="button" class="modal-filter-chip" id="modalF2fChip" onclick="toggleModalChip(this,'mode');checkModalLocationFilter();filterTutors();" data-value="face_to_face" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">🤝 Face to Face</button>
            </div>
          </div>

          <hr style="border:none;border-top:1px solid rgba(242,138,178,.18);margin:0 0 14px;">

          <!-- Rating -->
          <div style="margin-bottom:18px;">
            <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;">
              <i class="bi bi-star-fill" style="color:#E75A9B;margin-right:5px;"></i> Minimum Rating
            </p>
            <div style="display:flex;gap:8px;">
              <button type="button" class="modal-filter-chip" onclick="setModalRating(this,4);" data-value="4" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">⭐ 4 & up</button>
              <button type="button" class="modal-filter-chip" onclick="setModalRating(this,3);" data-value="3" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">⭐ 3 & up</button>
              <button type="button" class="modal-filter-chip" onclick="setModalRating(this,2);" data-value="2" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">⭐ 2 & up</button>
            </div>
          </div>

          <!-- Location (shows only when Face to Face selected) -->
          <div id="modalLocationBox" style="display:none;">
            <hr style="border:none;border-top:1px solid rgba(242,138,178,.18);margin:0 0 14px;">
            <div style="margin-bottom:18px;">
              <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;">
                <i class="bi bi-geo-alt" style="color:#E75A9B;margin-right:5px;"></i> Location
              </p>
              <div style="display:flex;flex-wrap:wrap;gap:8px;" id="modalLocationChips">
                <button type="button" class="modal-filter-chip" onclick="toggleModalChip(this,'location');filterTutors();" data-value="kuala lumpur" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Kuala Lumpur</button>
                <button type="button" class="modal-filter-chip" onclick="toggleModalChip(this,'location');filterTutors();" data-value="penang" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Penang</button>
                <button type="button" class="modal-filter-chip" onclick="toggleModalChip(this,'location');filterTutors();" data-value="johor bahru" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Johor Bahru</button>
                <button type="button" class="modal-filter-chip" onclick="toggleModalChip(this,'location');filterTutors();" data-value="kota kinabalu" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Kota Kinabalu</button>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:1px solid rgba(242,138,178,.18);">
            <button onclick="clearSearchFilters()" style="background:none;border:1px solid rgba(46,42,59,.12);color:#7A5570;font-size:13px;font-weight:900;cursor:pointer;padding:10px 18px;border-radius:999px;">✕ Clear all</button>
            <button onclick="toggleSearchFilters()" style="background:linear-gradient(135deg,#E75A9B,#F28AB2);border:none;color:white;font-size:13px;font-weight:900;cursor:pointer;padding:10px 22px;border-radius:999px;">Apply</button>
          </div>
        </div>
      </div>

      <button onclick="closeSearch()"
        style="width:44px;height:44px;border-radius:14px;border:1px solid rgba(46,42,59,.1);background:white;cursor:pointer;font-size:18px;flex:0 0 auto;">✕</button>
    </div>

    <p id="resultCount" style="font-size:12px;color:#9080a0;font-weight:700;margin:0 0 10px;"></p>

    <div id="tutorSearchResults" style="display:flex;flex-direction:column;gap:12px;">
      <?php
      $searchTutors = [];
      $stmtS = $conn->prepare("
        SELECT u.id, u.fullname, u.profile_pic, tp.rate,
               GROUP_CONCAT(DISTINCT tl.language) as languages,
               GROUP_CONCAT(DISTINCT ttm.mode) as teaching_modes,
               ul.location,
               ROUND(AVG(r.rating),1) as rating,
               COUNT(DISTINCT r.id) as review_count
        FROM users u
        JOIN tutor_profiles tp ON u.id = tp.user_id
        LEFT JOIN tutor_languages tl ON u.id = tl.user_id
        LEFT JOIN tutor_teaching_modes ttm ON u.id = ttm.user_id
        LEFT JOIN user_locations ul ON u.id = ul.user_id
        LEFT JOIN ratings r ON u.id = r.tutor_id
        WHERE u.role = 'tutor' AND u.status = 'approved'
        GROUP BY u.id ORDER BY u.fullname ASC
      ");
      $stmtS->execute();
      $searchTutors = $stmtS->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmtS->close();

      foreach ($searchTutors as $tutor):
        $sPic = !empty($tutor['profile_pic'])
            ? '../uploads/profiles/' . $tutor['profile_pic']
            : '../assets/img/profile-tutor.png';
      ?>
        <div class="search-tutor-item"
          data-lang="<?= htmlspecialchars(strtolower($tutor['languages'] ?? ''), ENT_QUOTES) ?>"
          data-mode="<?= htmlspecialchars(strtolower($tutor['teaching_modes'] ?? ''), ENT_QUOTES) ?>"
          data-location="<?= htmlspecialchars(strtolower($tutor['location'] ?? ''), ENT_QUOTES) ?>"
          data-rate="<?= $tutor['rate'] ?? 0 ?>"
          data-rating="<?= $tutor['rating'] ?? 0 ?>"
          style="display:flex;align-items:center;gap:14px;padding:14px;border-radius:20px;background:rgba(255,241,246,.8);border:1px solid rgba(242,138,178,.15);">
          <img src="<?= htmlspecialchars($sPic, ENT_QUOTES) ?>" style="width:56px;height:56px;border-radius:16px;object-fit:cover;background:#eee;flex:0 0 auto;">
          <div style="flex:1;min-width:0;">
            <strong style="display:block;"><?= htmlspecialchars($tutor['fullname'], ENT_QUOTES) ?></strong>
            <span style="display:block;color:#7B6178;font-size:13px;margin-top:4px;">
              <?= htmlspecialchars($tutor['languages'] ?? 'No language set', ENT_QUOTES) ?> · RM <?= $tutor['rate'] ?>/hr
              <?php if (!empty($tutor['teaching_modes'])): ?> · <?= htmlspecialchars($tutor['teaching_modes'], ENT_QUOTES) ?><?php endif; ?>
              <?php if (!empty($tutor['location'])): ?> · <?= htmlspecialchars($tutor['location'], ENT_QUOTES) ?><?php endif; ?>
              <?php if (!empty($tutor['rating'])): ?> · ⭐ <?= $tutor['rating'] ?> (<?= $tutor['review_count'] ?>)<?php endif; ?>
            </span>
          </div>
          <a href="tutor_profile.php?id=<?= $tutor['id'] ?>"
            style="padding:10px 18px;border-radius:999px;background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;font-size:13px;font-weight:700;white-space:nowrap;flex-shrink:0;text-decoration:none;">
            View
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>