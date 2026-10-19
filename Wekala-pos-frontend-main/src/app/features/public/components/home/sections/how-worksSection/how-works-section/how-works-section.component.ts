import {
  ChangeDetectionStrategy,
  Component
} from '@angular/core';

import { HowWorksContentComponent } from '../components/how-works-content/how-works-content.component';
import { HowWorksGridComponent } from '../components/how-works-grid/how-works-grid.component';

@Component({
  selector: 'app-how-works',
  standalone: true,
  imports: [
    HowWorksContentComponent,
    HowWorksGridComponent
  ],
  templateUrl: './how-works-section.component.html',
  styleUrl: './how-works-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class HowWorksSectionComponent {}