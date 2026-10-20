<?php

/*
 * Starter catalog copied into every new clinic. Prices are in major units and
 * converted with the clinic currency's exponent at provisioning time.
 */
return [
    'services' => [
        ['name' => 'Initial Assessment', 'code' => 'ASSESS', 'category' => 'Assessment', 'duration_minutes' => 60, 'price' => '1200', 'color' => '#3E8E76'],
        ['name' => 'Follow-up Session', 'code' => 'FOLLOW', 'category' => 'Treatment', 'duration_minutes' => 45, 'price' => '800', 'color' => '#4C7BD9'],
        ['name' => 'Sports Rehabilitation', 'code' => 'SPORTS', 'category' => 'Treatment', 'duration_minutes' => 60, 'price' => '1500', 'color' => '#8A63D2'],
        ['name' => 'Post-Surgery Rehab', 'code' => 'POSTOP', 'category' => 'Treatment', 'duration_minutes' => 60, 'price' => '1500', 'color' => '#D9534F'],
        ['name' => 'Dry Needling', 'code' => 'NEEDLE', 'category' => 'Modality', 'duration_minutes' => 30, 'price' => '1000', 'color' => '#E0A030'],
        ['name' => 'Home Visit Session', 'code' => 'HOME', 'category' => 'Home visit', 'duration_minutes' => 60, 'price' => '1800', 'color' => '#2B9AA8'],
    ],

    'package_plans' => [
        ['name' => '10-Session Rehab Pack', 'service_code' => 'FOLLOW', 'sessions_count' => 10, 'price' => '7000', 'validity_days' => 90],
        ['name' => '6-Session Sports Pack', 'service_code' => 'SPORTS', 'sessions_count' => 6, 'price' => '8100', 'validity_days' => 60],
    ],

    'consent_templates' => [
        [
            'title' => 'Consent to Physiotherapy Assessment and Treatment',
            'locale' => 'en',
            'is_required' => true,
            'body' => 'I consent to a physiotherapy assessment and to the treatment recommended by my physiotherapist. '
                .'The nature of the treatment, its expected benefits, possible risks (such as temporary soreness) and alternatives have been explained to me. '
                .'I understand that I may ask questions at any time and may withdraw my consent at any point. '
                .'I agree that my health information will be recorded and stored securely by the clinic for the purpose of my care.',
        ],
        [
            'title' => 'موافقة على التقييم والعلاج الطبيعي',
            'locale' => 'ar',
            'is_required' => false,
            'body' => 'أوافق على إجراء تقييم العلاج الطبيعي وعلى العلاج الذي يوصي به أخصائي العلاج الطبيعي. '
                .'لقد تم شرح طبيعة العلاج وفوائده المتوقعة ومخاطره المحتملة والبدائل المتاحة. '
                .'أفهم أنه يمكنني طرح الأسئلة في أي وقت وسحب موافقتي في أي مرحلة.',
        ],
    ],

    'exercises' => [
        ['Quad Set', 'knee', 'strengthening', 'beginner', 3, 15, 5, 'Lie on your back with the leg straight. Tighten the thigh muscle pushing the back of the knee into the bed. Hold, then relax.'],
        ['Straight Leg Raise', 'knee', 'strengthening', 'beginner', 3, 12, null, 'Lie on your back, bend the other knee. Keep the working leg straight, tighten the thigh and lift to the height of the bent knee. Lower slowly.'],
        ['Hamstring Stretch', 'knee', 'flexibility', 'beginner', 3, null, 30, 'Sit with the leg straight in front. Keeping the back straight, lean forward from the hips until a stretch is felt behind the thigh.'],
        ['Step Up', 'knee', 'strengthening', 'intermediate', 3, 15, null, 'Step up onto a low step with the affected leg, straighten fully, then step down slowly with control.'],
        ['Heel Slide', 'knee', 'mobility', 'beginner', 3, 15, null, 'Lie on your back. Slide the heel towards the buttock bending the knee as far as comfortable, then slide back.'],
        ['Terminal Knee Extension', 'knee', 'strengthening', 'intermediate', 3, 15, null, 'With a band behind the knee, start slightly bent and straighten the knee against the band.'],
        ['Wall Squat', 'knee', 'strengthening', 'intermediate', 3, 10, 10, 'Lean against a wall with feet forward. Slide down to a comfortable depth, hold, then slide up.'],
        ['Pendulum Exercise', 'shoulder', 'mobility', 'beginner', 2, 20, null, 'Lean forward supported on a table and let the arm hang. Gently swing the arm in small circles.'],
        ['Shoulder External Rotation with Band', 'shoulder', 'strengthening', 'intermediate', 3, 12, null, 'Elbow at your side bent to 90 degrees. Rotate the forearm outward against the band, keeping the elbow tucked in.'],
        ['Wall Slide', 'shoulder', 'mobility', 'beginner', 3, 10, null, 'Face a wall with forearms on it. Slide the arms up the wall as far as comfortable and return.'],
        ['Scapular Retraction', 'shoulder', 'posture', 'beginner', 3, 12, 5, 'Sit tall. Squeeze the shoulder blades together and down, hold, and relax.'],
        ['Sleeper Stretch', 'shoulder', 'flexibility', 'intermediate', 3, null, 30, 'Lie on the affected side with the arm at 90 degrees. Use the other hand to gently push the forearm toward the bed.'],
        ['Chin Tuck', 'neck', 'posture', 'beginner', 3, 10, 5, 'Sit tall and gently draw the chin straight back, making a double chin. Hold and release.'],
        ['Upper Trapezius Stretch', 'neck', 'flexibility', 'beginner', 3, null, 30, 'Tilt the ear towards the shoulder and gently assist with the hand until a stretch is felt on the opposite side.'],
        ['Neck Isometric Holds', 'neck', 'strengthening', 'beginner', 2, 5, 10, 'Place a hand on the forehead and push gently without moving the head. Repeat to the sides and back.'],
        ['Cat-Camel', 'back', 'mobility', 'beginner', 2, 10, null, 'On hands and knees, slowly round the back up then let it sag down, moving within a comfortable range.'],
        ['Bird Dog', 'back', 'stability', 'intermediate', 3, 10, 5, 'On hands and knees, extend the opposite arm and leg keeping the back level. Hold and switch sides.'],
        ['Glute Bridge', 'back', 'strengthening', 'beginner', 3, 12, 3, 'Lie on your back with knees bent. Squeeze the buttocks and lift the hips to form a straight line. Lower slowly.'],
        ['Knee to Chest Stretch', 'back', 'flexibility', 'beginner', 3, null, 30, 'Lie on your back and pull one knee towards the chest until a gentle stretch is felt in the lower back.'],
        ['Dead Bug', 'back', 'stability', 'intermediate', 3, 10, null, 'Lie on your back with arms and knees up. Slowly lower the opposite arm and leg while keeping the back flat.'],
        ['Ankle Pumps', 'ankle', 'mobility', 'beginner', 3, 20, null, 'Move the foot up and down at the ankle in a steady rhythm.'],
        ['Calf Raise', 'ankle', 'strengthening', 'beginner', 3, 15, null, 'Stand holding support. Rise up onto the toes, hold briefly and lower slowly.'],
        ['Single Leg Balance', 'ankle', 'balance', 'intermediate', 3, null, 30, 'Stand on one leg near a support. Hold your balance, progressing to eyes closed when safe.'],
        ['Wrist Flexor Stretch', 'wrist', 'flexibility', 'beginner', 3, null, 30, 'Arm straight, palm up. Gently pull the fingers back with the other hand until a stretch is felt.'],
        ['Clamshell', 'hip', 'strengthening', 'beginner', 3, 15, null, 'Lie on your side with knees bent. Keeping feet together, lift the top knee, then lower.'],
        ['Hip Flexor Stretch', 'hip', 'flexibility', 'beginner', 3, null, 30, 'Half-kneel with the affected knee down. Shift forward keeping the trunk upright until a stretch is felt at the front of the hip.'],
    ],
];
