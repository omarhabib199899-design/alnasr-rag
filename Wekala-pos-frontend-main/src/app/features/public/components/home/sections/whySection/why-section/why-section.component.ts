import {
  ChangeDetectionStrategy,
  Component
} from '@angular/core';

import { WhyContentComponent } from '../components/why-content/why-content.component';
import { WhyGridComponent } from '../components/why-grid/why-grid.component';

@Component({
  selector: 'app-why-section',
  standalone: true,
  imports: [
    WhyContentComponent,
    WhyGridComponent
  ],
  templateUrl: './why-section.component.html',
  styleUrl: './why-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WhySectionComponent {}